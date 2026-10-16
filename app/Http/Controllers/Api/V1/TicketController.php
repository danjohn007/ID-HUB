<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RecordsFieldWork;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\TicketCommentResource;
use App\Http\Resources\TicketResource;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Field\FieldTracker;
use App\Services\InventoryService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Tickets de soporte (personal). Los técnicos sin "tickets.manage" sólo pueden
 * operar (estado, comentarios, evidencias) los tickets que tienen asignados.
 */
class TicketController extends ApiController
{
    use RecordsFieldWork;

    public function index(Request $request)
    {
        $user = $this->apiUser();
        $q = Ticket::query()->with(['client', 'technician'])
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->open() : $q->whereIn('status', explode(',', $s)))
            ->when($request->query('priority'), fn ($q, $v) => $q->whereIn('priority', explode(',', $v)))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->query('technician_id'), fn ($q, $v) => $q->where('technician_id', $v))
            ->when($request->boolean('mine'), fn ($q) => $q->where('technician_id', $user->id))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('folio', 'like', "%$t%")->orWhere('subject', 'like', "%$t%")));
        $this->applySort($q, $request, ['id', 'created_at', 'sla_due_at', 'priority', 'updated_at'], '-created_at');

        return TicketResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(int $id): TicketResource
    {
        $ticket = Ticket::query()->with(['client', 'technician', 'comments.user', 'attachments', 'materials.item', 'materials.user'])->findOrFail($id);

        return new TicketResource($ticket);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'client_id' => ['nullable', 'integer', $this->existsInTenant('clients')],
            'category' => ['required', Rule::in(array_keys(config('wisp.ticket_categories')))],
            'priority' => ['nullable', Rule::in(array_keys(config('wisp.ticket_priorities')))],
            'subject' => 'required|string|max:190',
            'description' => 'nullable|string|max:5000',
            'technician_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', tenant()->id)],
            'repeater_id' => ['nullable', $this->existsInTenant('repeaters')],
            'source' => ['nullable', Rule::in(['admin', 'telefono', 'whatsapp', 'cliente'])],
        ]);
        $data['source'] ??= 'admin';

        $ticket = app(TicketService::class)->create(array_filter($data, fn ($v) => $v !== null));

        return $this->ok(['data' => new TicketResource($ticket->fresh(['client', 'technician']))], "Ticket {$ticket->folio} creado.", 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = $this->workable($id);
        $data = $this->check($request, [
            'status' => ['required', Rule::in(array_keys(config('wisp.ticket_status')))],
            'note' => 'nullable|string|max:1000',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);
        if (in_array($data['status'], ['cerrado', 'cancelado'], true) && ! $this->allows('tickets.manage')) {
            $this->deny('Sólo soporte o supervisión pueden cerrar o cancelar tickets.');
        }
        if (isset($data['lat'], $data['lng'])) {
            app(FieldTracker::class)->updateLocation($this->apiUser(), $data['lat'], $data['lng']);
        }
        if ($data['status'] !== $ticket->status) {
            app(TicketService::class)->changeStatus($ticket, $data['status'], $data['note'] ?? null);
        } elseif (! empty($data['note'])) {
            $ticket->addComment($data['note']);
        }

        return $this->ok(['data' => new TicketResource($ticket->fresh(['client', 'technician']))], 'Estado actualizado.');
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::query()->findOrFail($id);
        $data = $this->check($request, [
            'technician_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', tenant()->id)->where('active', 1)],
            'notify' => 'nullable|boolean',
        ]);
        $tech = User::forTenant()->findOrFail($data['technician_id']);
        app(TicketService::class)->assign($ticket, $tech, $data['notify'] ?? true);

        return $this->ok(['data' => new TicketResource($ticket->fresh(['client', 'technician']))], "Ticket asignado a {$tech->name}.");
    }

    public function comments(int $id)
    {
        $ticket = Ticket::query()->findOrFail($id);

        return TicketCommentResource::collection($ticket->comments()->with('user')->get());
    }

    public function addComment(Request $request, int $id): JsonResponse
    {
        $ticket = $this->workable($id);
        $data = $this->check($request, [
            'body' => 'required|string|max:5000',
            'internal' => 'nullable|boolean',
        ]);
        $comment = $ticket->addComment($data['body'], 'comment', (bool) ($data['internal'] ?? false));

        return $this->ok(['data' => new TicketCommentResource($comment->load('user'))], 'Comentario agregado.', 201);
    }

    /** Evidencia fotográfica (sección 24): archivo + tipo + GPS + fecha/hora. */
    public function addAttachment(Request $request, int $id): JsonResponse
    {
        $ticket = $this->workable($id);
        $data = $this->check($request, [
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,heic,pdf',
            'kind' => ['nullable', Rule::in(array_keys(config('wisp.evidence_kinds')))],
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'taken_at' => 'nullable|date',
        ]);
        $file = $request->file('file');
        $path = $file->store('attachments/'.tenant()->id.'/ticket/'.$ticket->id, 'public');

        $attachment = Attachment::create([
            'attachable_type' => 'ticket',
            'attachable_id' => $ticket->id,
            'kind' => $data['kind'] ?? 'otro',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime' => $file->getMimeType(),
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'taken_at' => $data['taken_at'] ?? now(),
            'uploaded_by' => $this->apiUser()->id,
        ]);
        if (isset($data['lat'], $data['lng'])) {
            app(FieldTracker::class)->updateLocation($this->apiUser(), $data['lat'], $data['lng']);
        }
        $ticket->addComment('Evidencia agregada: '.config('wisp.evidence_kinds.'.$attachment->kind, $attachment->kind), 'system', false,
            array_filter(['attachment_id' => $attachment->id, 'lat' => $attachment->lat, 'lng' => $attachment->lng]));

        return $this->ok(['data' => new AttachmentResource($attachment)], 'Evidencia guardada.', 201);
    }

    /** Material usado en el ticket: se descuenta del inventario (sección 23 "Registrar materiales"). */
    public function addMaterial(Request $request, int $id): JsonResponse
    {
        $ticket = $this->workable($id);
        $movement = $this->recordMaterial($request, [
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'reference' => $ticket->folio,
            'notes' => 'Material usado en ticket '.$ticket->folio,
        ]);
        $ticket->addComment('Material registrado: '.app(InventoryService::class)->qty(abs((float) $movement->quantity)).' '
            .$movement->item->unit.' de '.$movement->item->name, 'system');

        return $this->ok(['data' => new InventoryMovementResource($movement)], 'Material registrado y descontado del inventario.', 201);
    }

    public function returnMaterial(int $id, int $movementId): JsonResponse
    {
        $ticket = $this->workable($id);
        $m = InventoryMovement::query()->where('ticket_id', $ticket->id)->whereIn('type', ['instalacion', 'salida'])->findOrFail($movementId);
        $this->returnMovement($m, [
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id, 'reference' => $ticket->folio,
            'notes' => 'Devolución de material del ticket '.$ticket->folio,
        ]);

        return $this->ok([], 'Material devuelto al inventario.');
    }

    /** Firma de conformidad del cliente (sección 23 "Obtener firma del cliente"). */
    public function signature(Request $request, int $id): JsonResponse
    {
        $ticket = $this->workable($id);
        $sig = $this->signatureFrom($request);
        $path = 'signatures/'.$ticket->tenant_id.'/ticket-'.$ticket->id.'-'.now()->format('YmdHis').'.png';
        Storage::disk('public')->put($path, $sig['png']);
        if ($ticket->signature_path) {
            Storage::disk('public')->delete($ticket->signature_path);
        }
        $ticket->forceFill(['signature_path' => $path, 'signed_by_name' => $sig['signed_by'], 'signed_at' => now()])->save();
        $ticket->addComment('Firma de conformidad capturada ('.$sig['signed_by'].')', 'system');

        return $this->ok(['data' => new TicketResource($ticket->fresh(['client', 'technician']))], 'Firma guardada.', 201);
    }

    /** Ticket que el usuario puede operar: tickets.manage, o técnico asignado con field.work. */
    protected function workable(int $id): Ticket
    {
        $ticket = Ticket::query()->findOrFail($id);
        $user = $this->apiUser();
        if ($this->allows('tickets.manage') || ($this->allows('field.work') && (int) $ticket->technician_id === (int) $user->id)) {
            return $ticket;
        }
        $this->deny('Sólo puedes operar los tickets que tienes asignados.');
    }
}
