<?php

namespace App\Http\Resources;

use Illuminate\Support\Facades\Storage;

/**
 * Orden de instalación para la app del técnico (sección 40): cliente o prospecto, plan,
 * checklist, mediciones, evidencias, materiales y firma.
 */
class InstallationOrderResource extends ApiResource
{
    public function toArray($request): array
    {
        $client = $this->client;
        $prospect = $this->prospect;
        $lat = $client?->lat ?? $prospect?->lat;
        $lng = $client?->lng ?? $prospect?->lng;
        $checklist = $this->checklist ?? [];

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => self::catalog('installation_status', $this->status),
            'scheduled_at' => self::datetime($this->scheduled_at),
            'started_at' => self::datetime($this->started_at),
            'completed_at' => self::datetime($this->completed_at),
            'contact' => [
                'name' => $client?->full_name ?? $prospect?->name,
                'phone' => $client?->phone ?? $prospect?->phone,
                'whatsapp' => $client?->whatsapp ?? $prospect?->whatsapp,
                'address' => $client ? trim($client->address.' '.$client->colonia) : $prospect?->address,
                'references' => $client?->references,
            ],
            'client' => $client ? new ClientSummaryResource($client) : null,
            'prospect_id' => $this->prospect_id,
            'location' => self::coords($lat, $lng),
            'navigation_url' => $lat && $lng ? 'https://www.google.com/maps/dir/?api=1&destination='.$lat.','.$lng : null,
            'plan' => $this->plan ? ['id' => $this->plan->id, 'name' => $this->plan->name, 'download_mbps' => (int) $this->plan->download_mbps] : null,
            'repeater' => $this->repeater ? ['id' => $this->repeater->id, 'code' => $this->repeater->code, 'name' => $this->repeater->name] : null,
            'checklist' => collect(config('wisp.installation_checklist'))->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'done' => (bool) ($checklist[$key] ?? false),
                // fotografía y firma se marcan solas al subir la foto / guardar la firma
                'auto' => in_array($key, ['fotografia', 'firma'], true),
            ])->values(),
            'measures' => [
                'signal_dbm' => $this->signal_dbm !== null ? (float) $this->signal_dbm : null,
                'speed_down' => $this->speed_down !== null ? (float) $this->speed_down : null,
                'speed_up' => $this->speed_up !== null ? (float) $this->speed_up : null,
            ],
            'signature' => $this->signature_path ? [
                'url' => Storage::disk('public')->url($this->signature_path),
                'signed_by' => $this->signed_by_name,
            ] : null,
            'notes' => $this->notes,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'materials' => InventoryMovementResource::collection($this->whenLoaded('materials')),
            'can_complete' => in_array($this->status, ['programada', 'en_proceso', 'aceptada'], true),
        ];
    }
}
