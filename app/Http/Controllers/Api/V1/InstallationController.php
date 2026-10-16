<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RecordsFieldWork;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\InstallationOrderResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\InstallationOrder;
use App\Models\InventoryMovement;
use App\Services\Field\FieldTracker;
use App\Services\InstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Órdenes de instalación en la app del técnico (sección 40): iniciar, checklist, mediciones,
 * fotografías, materiales, firma y finalizar. La activación del cliente sigue en el panel
 * (requiere installations.manage). Mismas reglas que Installations\Show.
 */
class InstallationController extends ApiController
{
    use RecordsFieldWork;

    protected const RELATIONS = ['client', 'prospect', 'plan', 'repeater'];

    /** Órdenes asignadas al técnico (o todas con ?all=1 si tiene installations.manage). */
    public function index(Request $request)
    {
        $all = $request->boolean('all') && $this->allows('installations.manage');
        $status = $request->query('status', 'open');
        $q = InstallationOrder::query()->with(self::RELATIONS)
            ->when(! $all, fn ($q) => $q->where('technician_id', $this->apiUser()->id))
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['aceptada', 'programada', 'en_proceso']))
            ->when($status !== 'open', fn ($q) => $q->whereIn('status', explode(',', $status)))
            ->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at');

        return InstallationOrderResource::collection($q->paginate($this->perPage($request, 50)));
    }

    public function show(int $id): InstallationOrderResource
    {
        $order = $this->visible($id);

        return new InstallationOrderResource($order->load(['attachments', 'materials.item', 'materials.user']));
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        app(InstallationService::class)->start($order);
        $this->trackGps($request);

        return $this->fresh($order, 'Instalación iniciada.');
    }

    /** Marca / desmarca un punto del checklist (fotografía y firma se marcan solas). */
    public function checklist(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $data = $this->check($request, [
            'key' => ['required', Rule::in(array_keys(config('wisp.installation_checklist')))],
            'done' => 'required|boolean',
        ], ['key' => 'punto del checklist', 'done' => 'marcado']);
        if (in_array($data['key'], ['fotografia', 'firma'], true)) {
            $this->invalid('key', $data['key'] === 'firma' ? 'Se marca al guardar la firma del cliente.' : 'Se marca al subir una fotografía.');
        }
        app(InstallationService::class)->toggleChecklist($order, $data['key'], (bool) $data['done']);

        return $this->fresh($order, 'Checklist actualizado.');
    }

    /** Prueba de señal y de velocidad: marcan los puntos "senal" y "velocidad". */
    public function measures(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $data = $this->check($request, [
            'signal_dbm' => 'nullable|numeric|between:-120,0',
            'speed_down' => 'nullable|numeric|between:0,10000',
            'speed_up' => 'nullable|numeric|between:0,10000',
        ], ['signal_dbm' => 'señal', 'speed_down' => 'velocidad de bajada', 'speed_up' => 'velocidad de subida']);
        $list = $order->checklist ?? [];
        if (isset($data['signal_dbm'])) {
            $list['senal'] = true;
        }
        if (isset($data['speed_down'])) {
            $list['velocidad'] = true;
        }
        $order->update(array_intersect_key($data, array_flip(['signal_dbm', 'speed_down', 'speed_up'])) + ['checklist' => $list]);

        return $this->fresh($order, 'Mediciones guardadas.');
    }

    public function addAttachment(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $data = $this->check($request, [
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,heic',
            'kind' => ['nullable', Rule::in(array_keys(config('wisp.evidence_kinds')))],
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'taken_at' => 'nullable|date',
        ]);
        $file = $request->file('file');
        $attachment = $order->attachments()->create([
            'tenant_id' => $order->tenant_id,
            'kind' => $data['kind'] ?? 'instalacion',
            'path' => $file->store('evidences/'.$order->tenant_id.'/installations', 'public'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime' => $file->getMimeType(),
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'taken_at' => $data['taken_at'] ?? now(),
            'uploaded_by' => $this->apiUser()->id,
        ]);
        $list = $order->checklist ?? [];
        $list['fotografia'] = true;
        $order->update(['checklist' => $list]);
        $this->trackGps($request);

        return $this->ok(['data' => new AttachmentResource($attachment)], 'Fotografía guardada.', 201);
    }

    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        $order = $this->workable($id);
        $a = $order->attachments()->findOrFail($attachmentId);
        if (! $this->allows('installations.manage') && (int) $a->uploaded_by !== (int) $this->apiUser()->id) {
            $this->deny('Sólo puedes borrar las fotografías que tú subiste.');
        }
        Storage::disk('public')->delete($a->path);
        $a->delete();

        return $this->ok([], 'Fotografía eliminada.');
    }

    public function addMaterial(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $movement = $this->recordMaterial($request, [
            'installation_order_id' => $order->id, 'client_id' => $order->client_id, 'reference' => $order->folio,
            'notes' => 'Material usado en instalación '.$order->folio,
        ]);

        return $this->ok(['data' => new InventoryMovementResource($movement)], 'Material registrado y descontado del inventario.', 201);
    }

    public function returnMaterial(int $id, int $movementId): JsonResponse
    {
        $order = $this->workable($id);
        $m = InventoryMovement::query()->where('installation_order_id', $order->id)->whereIn('type', ['instalacion', 'salida'])->findOrFail($movementId);
        $this->returnMovement($m, [
            'installation_order_id' => $order->id, 'client_id' => $order->client_id, 'reference' => $order->folio,
            'notes' => 'Devolución de material de la instalación '.$order->folio,
        ]);

        return $this->ok([], 'Material devuelto al inventario.');
    }

    public function signature(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $sig = $this->signatureFrom($request);
        app(InstallationService::class)->saveSignature($order, 'data:image/png;base64,'.base64_encode($sig['png']), $sig['signed_by']);

        return $this->fresh($order, 'Firma guardada.', 201);
    }

    /** Finaliza la instalación: todos los puntos del checklist deben estar completos. */
    public function complete(Request $request, int $id): JsonResponse
    {
        $order = $this->workable($id);
        $missing = collect(config('wisp.installation_checklist'))->reject(fn ($label, $key) => $order->checklist[$key] ?? false);
        if ($missing->isNotEmpty()) {
            $this->invalid('checklist', 'Faltan puntos del checklist: '.$missing->implode(', ').'.');
        }
        app(InstallationService::class)->complete($order);
        $this->trackGps($request);

        return $this->fresh($order, 'Instalación finalizada. Lista para activación en el panel.');
    }

    /** Orden visible: installations.manage o técnico asignado. */
    protected function visible(int $id): InstallationOrder
    {
        $order = InstallationOrder::query()->with(self::RELATIONS)->findOrFail($id);
        if (! $this->allows('installations.manage') && ! ($this->allows('field.work') && (int) $order->technician_id === (int) $this->apiUser()->id)) {
            $this->deny('Esta orden de instalación no está asignada a ti.');
        }

        return $order;
    }

    /** Orden que se puede trabajar en campo: visible y no cerrada. */
    protected function workable(int $id): InstallationOrder
    {
        $order = $this->visible($id);
        if (in_array($order->status, ['instalada', 'activada', 'cancelada'], true)) {
            $this->deny('La orden ya está '.mb_strtolower(config("wisp.installation_status.{$order->status}.0")).'.', 422);
        }

        return $order;
    }

    protected function fresh(InstallationOrder $order, string $message, int $status = 200): JsonResponse
    {
        $order = $order->fresh([...self::RELATIONS, 'attachments', 'materials.item', 'materials.user']);

        return $this->ok(['data' => new InstallationOrderResource($order)], $message, $status);
    }

    protected function trackGps(Request $request): void
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            app(FieldTracker::class)->updateLocation($this->apiUser(), $lat, $lng);
        }
    }
}
