<?php

namespace App\Livewire\Tickets\Concerns;

use App\Models\Attachment;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Ticket;
use App\Services\Field\FieldTracker;
use App\Services\InventoryService;
use App\Services\TicketService;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

/**
 * Trabajo sobre un ticket compartido por el detalle (tickets.show) y la app de campo (field.work):
 * comentarios, cambio de estado, evidencias con GPS, materiales, firma del cliente y llegada/salida.
 * Requiere una propiedad pública `Ticket $ticket` en el componente.
 */
trait ManagesTicketWork
{
    use WithFileUploads;

    public string $commentBody = '';

    public bool $commentInternal = false;

    public string $newStatus = '';

    public string $statusNote = '';

    public $photo;

    public string $photoKind = 'antes';

    public ?string $photoLat = null;

    public ?string $photoLng = null;

    public array $material = ['item_id' => '', 'quantity' => 1, 'type' => 'instalacion', 'serial' => ''];

    public ?string $signature = null;

    public string $signedBy = '';

    /** ¿El usuario puede trabajar el ticket? (administración o técnico asignado) */
    public function canWork(): bool
    {
        $user = auth()->user();

        return $user->can('tickets.manage') || ($user->can('field.work') && (int) $this->ticket->technician_id === (int) $user->id);
    }

    protected function ensureCanWork(): void
    {
        abort_unless($this->canWork(), 403, 'No tienes permiso para trabajar este ticket.');
    }

    public function addComment(): void
    {
        $this->ensureCanWork();
        $this->validate(['commentBody' => 'required|string|min:2|max:2000'], [], ['commentBody' => 'comentario']);
        $this->ticket->addComment(trim($this->commentBody), 'comment', $this->commentInternal);
        $this->ticket->touch();
        $this->reset('commentBody', 'commentInternal');
        $this->toast('Comentario agregado');
    }

    public function updateStatus(?string $status = null): void
    {
        $this->ensureCanWork();
        $status ??= $this->newStatus;
        $this->newStatus = $status;
        $allowed = array_keys(config('wisp.ticket_status'));
        if (! auth()->user()->can('tickets.manage')) {
            $allowed = ['en_proceso', 'en_espera', 'resuelto'];
        }
        $this->validate([
            'newStatus' => 'required|in:'.implode(',', $allowed),
            'statusNote' => ($status === 'resuelto' ? 'required|min:5|' : 'nullable|').'string|max:190',
        ], ['statusNote.required' => 'Describe la solución aplicada.'], ['newStatus' => 'estado', 'statusNote' => 'nota de resolución']);

        if ($status === $this->ticket->status) {
            $this->toast('El ticket ya está en ese estado', 'info');

            return;
        }
        app(TicketService::class)->changeStatus($this->ticket, $status, $this->statusNote ?: null);
        $this->ticket->refresh();
        $this->reset('statusNote');
        $this->afterStatusChange($status);
        $this->toast('Estado actualizado a '.config("wisp.ticket_status.$status.0"));
    }

    protected function afterStatusChange(string $status): void {}

    public function updatedPhoto(): void
    {
        $this->uploadPhoto();
    }

    public function uploadPhoto(): void
    {
        $this->ensureCanWork();
        $this->validate([
            'photo' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,heic,heif,pdf',
            'photoKind' => 'required|in:'.implode(',', array_keys(config('wisp.evidence_kinds'))),
        ], [], ['photo' => 'foto', 'photoKind' => 'tipo']);

        $path = $this->photo->store('evidences/'.$this->ticket->tenant_id.'/tickets/'.$this->ticket->id, 'public');
        $valid = is_numeric($this->photoLat) && is_numeric($this->photoLng);
        $this->ticket->attachments()->create([
            'tenant_id' => $this->ticket->tenant_id, 'kind' => $this->photoKind, 'path' => $path,
            'original_name' => mb_substr($this->photo->getClientOriginalName(), 0, 190), 'mime' => $this->photo->getMimeType(),
            'lat' => $valid ? $this->photoLat : null, 'lng' => $valid ? $this->photoLng : null,
            'taken_at' => now(), 'uploaded_by' => auth()->id(),
        ]);
        if ($valid) {
            app(FieldTracker::class)->updateLocation(auth()->user(), $this->photoLat, $this->photoLng);
        }
        $this->ticket->addComment('Evidencia agregada: '.config('wisp.evidence_kinds.'.$this->photoKind), 'system');
        $this->reset('photo');
        $this->toast(config('wisp.evidence_kinds.'.$this->photoKind).' guardada'.($valid ? ' con ubicación GPS' : ''));
    }

    public function deletePhoto(int $id): void
    {
        $this->ensureCanWork();
        $a = Attachment::query()->where('attachable_type', 'ticket')->where('attachable_id', $this->ticket->id)->findOrFail($id);
        abort_unless(auth()->user()->can('tickets.manage') || (int) $a->uploaded_by === (int) auth()->id(), 403);
        Storage::disk('public')->delete($a->path);
        $a->delete();
        $this->toast('Evidencia eliminada', 'warning');
    }

    public function addMaterial(InventoryService $inventory): void
    {
        $this->ensureCanWork();
        $this->validate([
            'material.item_id' => 'required|integer',
            'material.quantity' => 'required|numeric|min:0.01|max:100000',
            'material.type' => 'required|in:instalacion,salida',
            'material.serial' => 'nullable|string|max:120',
        ], [], ['material.item_id' => 'artículo', 'material.quantity' => 'cantidad']);

        $item = InventoryItem::query()->where('active', true)->findOrFail($this->material['item_id']);
        $inventory->move($item, $this->material['type'], (float) $this->material['quantity'], [
            'ticket_id' => $this->ticket->id, 'client_id' => $this->ticket->client_id,
            'serial' => $this->material['serial'] ?: null, 'reference' => $this->ticket->folio,
            'notes' => 'Material usado en ticket '.$this->ticket->folio,
        ]);
        $this->ticket->addComment('Material registrado: '.$inventory->qty($this->material['quantity']).' '.$item->unit.' de '.$item->name, 'system');
        $this->material = ['item_id' => '', 'quantity' => 1, 'type' => 'instalacion', 'serial' => ''];
        $this->toast('Material registrado y descontado del inventario');
    }

    /** Devuelve al almacén un material registrado por error. */
    public function returnMaterial(int $movementId, InventoryService $inventory): void
    {
        $this->ensureCanWork();
        $m = InventoryMovement::query()->where('ticket_id', $this->ticket->id)->whereIn('type', ['instalacion', 'salida'])->findOrFail($movementId);
        $inventory->move($m->item, 'devolucion', abs((float) $m->quantity), [
            'ticket_id' => $this->ticket->id, 'client_id' => $this->ticket->client_id, 'serial' => $m->serial,
            'reference' => $this->ticket->folio, 'notes' => 'Devolución de material del ticket '.$this->ticket->folio,
        ]);
        $this->toast('Material devuelto al inventario', 'warning');
    }

    public function saveSignature(): void
    {
        $this->ensureCanWork();
        $this->validate(['signedBy' => 'required|string|min:3|max:120'], [], ['signedBy' => 'nombre de quien firma']);
        if (! $this->signature || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $this->signature, $m)) {
            $this->addError('signature', 'Captura la firma del cliente.');

            return;
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 2 * 1024 * 1024 || ! str_starts_with($png, "\x89PNG")) {
            $this->addError('signature', 'La firma no es válida.');

            return;
        }
        $path = 'signatures/'.$this->ticket->tenant_id.'/ticket-'.$this->ticket->id.'-'.now()->format('YmdHis').'.png';
        Storage::disk('public')->put($path, $png);
        if ($this->ticket->signature_path) {
            Storage::disk('public')->delete($this->ticket->signature_path);
        }
        $this->ticket->forceFill(['signature_path' => $path, 'signed_by_name' => trim($this->signedBy), 'signed_at' => now()])->save();
        $this->ticket->addComment('Firma de conformidad capturada ('.trim($this->signedBy).')', 'system');
        $this->reset('signature');
        $this->dispatch('signature-saved');
        $this->toast('Firma guardada');
    }

    public function arriveHere($lat = null, $lng = null): void
    {
        $this->ensureCanWork();
        app(FieldTracker::class)->arriveTicket($this->ticket, auth()->user(), $lat, $lng);
        $this->ticket->refresh();
        $this->toast('Llegada registrada'.(is_numeric($lat) ? ' con GPS' : ''));
    }

    public function departHere($lat = null, $lng = null): void
    {
        $this->ensureCanWork();
        app(FieldTracker::class)->departTicket($this->ticket, auth()->user(), $lat, $lng);
        $this->ticket->refresh();
        $this->toast('Salida registrada');
    }

    public function onWay($lat = null, $lng = null): void
    {
        $this->ensureCanWork();
        app(FieldTracker::class)->onWay($this->ticket, auth()->user(), $lat, $lng);
        $this->ticket->refresh();
        $this->toast('Se notificó al cliente que vas en camino');
    }

    /** Datos comunes para las vistas. */
    protected function workData(): array
    {
        $t = $this->ticket;

        return [
            'attachments' => $t->attachments()->with('uploadedBy:id,name')->latest()->get(),
            'materials' => $t->materials()->with('item:id,name,unit,sku', 'user:id,name')->latest()->get(),
            'items' => InventoryItem::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'sku', 'stock', 'unit'])
                ->mapWithKeys(fn ($i) => [$i->id => $i->name.' ('.app(InventoryService::class)->qty($i->stock).' '.$i->unit.')']),
            'canWork' => $this->canWork(),
        ];
    }
}
