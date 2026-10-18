<?php

namespace App\Http\Resources;

use App\Services\InstallationService;
use Illuminate\Support\Facades\Storage;

/** Orden de instalación para la app del técnico (sección 40). */
class InstallationOrderResource extends ApiResource
{
    public function toArray($request): array
    {
        $loc = $this->client ?? $this->prospect;
        $lat = $loc?->lat;
        $lng = $loc?->lng;
        $checklist = $this->checklist ?? [];

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'status' => self::catalog('installation_status', $this->status),
            'step' => app(InstallationService::class)->stepIndex($this->resource),
            // Mismas reglas que la vista web: se inicia cuando está programada y se trabaja en proceso
            'can_start' => $this->status === 'programada',
            'editable' => $this->status === 'en_proceso',
            'customer_name' => $this->customerName(),
            'phone' => $this->client?->whatsapp ?? $this->client?->phone ?? $this->prospect?->whatsapp ?? $this->prospect?->phone,
            'address' => $loc ? trim($loc->address.(($loc->colonia ?? null) ? ', '.$loc->colonia : '')) : null,
            'references' => $this->client?->references ?? $this->prospect?->references ?? null,
            'location' => self::coords($lat, $lng),
            'navigation_url' => $lat && $lng ? 'https://www.google.com/maps/dir/?api=1&destination='.$lat.','.$lng : null,
            'client' => $this->client ? new ClientSummaryResource($this->client) : null,
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id, 'name' => $this->plan->name, 'speed' => $this->plan->speedLabel(), 'price' => self::money($this->plan->price),
            ] : null),
            'repeater' => $this->whenLoaded('repeater', fn () => $this->repeater ? ['id' => $this->repeater->id, 'code' => $this->repeater->code, 'name' => $this->repeater->name] : null),
            'technician' => $this->whenLoaded('technician', fn () => $this->technician ? new UserSummaryResource($this->technician) : null),
            'scheduled_at' => self::datetime($this->scheduled_at),
            'started_at' => self::datetime($this->started_at),
            'completed_at' => self::datetime($this->completed_at),
            'quote_amount' => self::money($this->quote_amount),
            'notes' => $this->notes,
            'checklist' => collect(config('wisp.installation_checklist'))->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'done' => (bool) ($checklist[$key] ?? false),
                // Fotografía y firma se marcan solas al subir la foto / guardar la firma
                'auto' => in_array($key, ['fotografia', 'firma'], true),
            ])->values(),
            'checklist_progress' => $this->checklistProgress(),
            'measures' => [
                'signal_dbm' => $this->signal_dbm !== null ? (float) $this->signal_dbm : null,
                'speed_down' => $this->speed_down !== null ? (float) $this->speed_down : null,
                'speed_up' => $this->speed_up !== null ? (float) $this->speed_up : null,
            ],
            'signature' => $this->signature_path ? [
                'url' => Storage::disk('public')->url($this->signature_path),
                'signed_by_name' => $this->signed_by_name,
            ] : null,
            'attachments' => $this->whenLoaded('attachments', fn () => AttachmentResource::collection($this->attachments->where('kind', '!=', 'firma')->values())),
            'materials' => $this->whenLoaded('materials', fn () => InventoryMovementResource::collection($this->materials)),
            'created_at' => self::datetime($this->created_at),
            'updated_at' => self::datetime($this->updated_at),
        ];
    }
}
