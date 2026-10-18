<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RegistersMaterials;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\InstallationOrderResource;
use App\Models\InstallationOrder;
use App\Models\InventoryMovement;
use App\Services\Field\FieldTracker;
use App\Services\InstallationService;
use App\Support\InputRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Órdenes de instalación en la app del técnico (secciones 23 y 40). Mismo flujo que la vista
 * web de la orden (Installations\Show): iniciar → checklist, mediciones, fotos, firma, materiales → finalizar.
 * La cotización, programación y activación siguen siendo del panel (installations.manage).
 */
class InstallationController extends ApiController
{
    use RegistersMaterials;

    /** Órdenes del técnico. ?scope=open (aceptada, programada, en proceso) | done (instalada, activada) | all */
    public function index(Request $request)
    {
        $scope = $request->query('scope', 'open');
        $q = InstallationOrder::query()->with(['client', 'prospect', 'plan', 'repeater', 'technician'])
            ->when(! $this->allows('installations.manage') || $request->boolean('mine', true), fn ($q) => $q->where('technician_id', $this->apiUser()->id))
            ->when($scope === 'open', fn ($q) => $q->whereIn('status', ['aceptada', 'programada', 'en_proceso']))
            ->when($scope === 'done', fn ($q) => $q->whereIn('status', ['instalada', 'activada']))
            ->orderByRaw("CASE status WHEN 'en_proceso' THEN 0 WHEN 'programada' THEN 1 WHEN 'aceptada' THEN 2 WHEN 'instalada' THEN 3 WHEN 'activada' THEN 4 ELSE 5 END")
            ->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at')->orderByDesc('id');

        return InstallationOrderResource::collection($q->paginate($this->perPage($request)));
    }

    public function show(int $id): InstallationOrderResource
    {
        return new InstallationOrderResource($this->detailed($this->order($id)));
    }

    public function start(Request $request, int $id): JsonResponse
    {
        $order = $this->order($id);
        if ($order->status !== 'programada') {
            $this->deny('Sólo puedes iniciar una instalación programada.', 422);
        }
        app(InstallationService::class)->start($order);
        $this->trackGps($request);

        return $this->ok(['data' => $this->resource($order)], 'Instalación iniciada.');
    }

    public function checklist(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $data = $this->check($request, [
            'key' => ['required', Rule::in(array_keys(config('wisp.installation_checklist')))],
            'done' => 'nullable|boolean',
        ], ['key' => 'punto del checklist']);
        if (in_array($data['key'], ['fotografia', 'firma'], true)) {
            $this->deny($data['key'] === 'firma' ? 'Se marca al guardar la firma del cliente.' : 'Se marca al subir una fotografía.', 422);
        }
        $done = array_key_exists('done', $data) && $data['done'] !== null ? (bool) $data['done'] : ! ($order->checklist[$data['key']] ?? false);
        app(InstallationService::class)->toggleChecklist($order, $data['key'], $done);

        return $this->ok(['data' => $this->resource($order)]);
    }

    public function measures(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $m = $this->check($request, [
            'signal_dbm' => 'nullable|numeric|between:-120,0',
            'speed_down' => 'nullable|numeric|between:0,10000',
            'speed_up' => 'nullable|numeric|between:0,10000',
        ], ['signal_dbm' => 'señal', 'speed_down' => 'bajada', 'speed_up' => 'subida']);
        $m = array_merge(['signal_dbm' => null, 'speed_down' => null, 'speed_up' => null], $m);
        $list = $order->checklist ?? [];
        if ($m['signal_dbm'] !== null) {
            $list['senal'] = true;
        }
        if ($m['speed_down'] !== null) {
            $list['velocidad'] = true;
        }
        $order->update($m + ['checklist' => $list]);

        return $this->ok(['data' => $this->resource($order)], 'Mediciones guardadas.');
    }

    public function addAttachment(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $data = $this->check($request, [
            'file' => 'required|file|image|max:10240',
            'kind' => ['nullable', Rule::in(array_diff(array_keys(config('wisp.evidence_kinds')), ['firma']))],
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'taken_at' => 'nullable|date',
        ], ['file' => 'fotografía']);
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

        return $this->ok(['data' => new AttachmentResource($attachment)], 'Fotografía agregada.', 201);
    }

    public function deleteAttachment(int $id, int $attachmentId): JsonResponse
    {
        $order = $this->editable($id);
        $a = $order->attachments()->where('kind', '!=', 'firma')->findOrFail($attachmentId);
        Storage::disk('public')->delete($a->path);
        $a->delete();

        return $this->ok([], 'Fotografía eliminada.');
    }

    /** Firma del cliente: PNG en base64 (data URL) o archivo PNG. Marca el punto "firma". */
    public function signature(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $data = $this->check($request, [
            'signed_by' => 'required|string|max:150|'.InputRules::NAME,
            'signature' => ['required_without:file', 'nullable', 'string', 'starts_with:data:image/png;base64,', 'max:3000000'],
            'file' => 'required_without:signature|nullable|file|mimes:png|max:2048',
        ], ['signed_by' => 'nombre de quien firma', 'signature' => 'firma', 'file' => 'firma']);
        $dataUrl = $request->hasFile('file')
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($request->file('file')->getRealPath()))
            : (string) $data['signature'];
        app(InstallationService::class)->saveSignature($order, $dataUrl, $data['signed_by']);

        return $this->ok(['data' => $this->resource($order)], 'Firma guardada.');
    }

    public function addMaterial(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $resource = $this->registerMaterial($request, ['installation_order_id' => $order->id, 'client_id' => $order->client_id], $order->folio, 'la orden de instalación');

        return $this->ok(['data' => $resource], 'Material registrado y descontado del inventario.', 201);
    }

    public function returnMaterial(int $id, int $movementId): JsonResponse
    {
        $order = $this->editable($id);
        $m = InventoryMovement::query()->where('installation_order_id', $order->id)->whereIn('type', ['instalacion', 'salida'])->with('item')->findOrFail($movementId);
        $resource = $this->returnMaterialMovement($m, ['installation_order_id' => $order->id, 'client_id' => $order->client_id], $order->folio, 'Devolución de material de la orden de instalación '.$order->folio);

        return $this->ok(['data' => $resource], 'Material devuelto al inventario.', 201);
    }

    /** Finaliza la instalación (checklist completo) → queda lista para activación en el panel. */
    public function complete(Request $request, int $id): JsonResponse
    {
        $order = $this->editable($id);
        $missing = collect(config('wisp.installation_checklist'))->reject(fn ($label, $key) => $order->checklist[$key] ?? false);
        if ($missing->isNotEmpty()) {
            return response()->json([
                'message' => 'Faltan puntos del checklist: '.$missing->implode(', '),
                'missing' => $missing->keys()->values(),
            ], 422);
        }
        app(InstallationService::class)->complete($order);
        $this->trackGps($request);

        return $this->ok(['data' => $this->resource($order)], 'Instalación finalizada. Lista para activación.');
    }

    /** Orden visible para el usuario: installations.manage o técnico asignado. */
    protected function order(int $id): InstallationOrder
    {
        $order = InstallationOrder::query()->findOrFail($id);
        if (! $this->allows('installations.manage') && (int) $order->technician_id !== (int) $this->apiUser()->id) {
            $this->deny('Esta orden de instalación no está asignada a ti.');
        }

        return $order;
    }

    /** Orden en proceso (lo único editable desde campo, igual que la vista web). */
    protected function editable(int $id): InstallationOrder
    {
        $order = $this->order($id);
        if ($order->status !== 'en_proceso') {
            $this->deny($order->status === 'programada'
                ? 'Primero inicia la instalación.'
                : 'La orden ya no se puede modificar ('.config("wisp.installation_status.{$order->status}.0", $order->status).').', 422);
        }

        return $order;
    }

    protected function detailed(InstallationOrder $order): InstallationOrder
    {
        return $order->load([
            'client', 'prospect', 'plan', 'repeater', 'technician', 'attachments',
            'materials' => fn ($q) => $q->with('item', 'user')->latest(),
        ]);
    }

    protected function resource(InstallationOrder $order): InstallationOrderResource
    {
        return new InstallationOrderResource($this->detailed($order->fresh()));
    }

    protected function trackGps(Request $request): void
    {
        if (is_numeric($request->input('lat')) && is_numeric($request->input('lng'))) {
            app(FieldTracker::class)->updateLocation($this->apiUser(), $request->input('lat'), $request->input('lng'));
        }
    }
}
