<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\InventoryMovement;
use App\Models\NetworkDevice;
use App\Models\Plan;
use App\Models\Repeater;
use App\Services\Network\MonitoringService;
use App\Support\Audit;
use App\Support\Evidence;
use App\Support\FieldClock;
use Illuminate\Support\Facades\DB;

/**
 * Flujo de la orden de instalación (sección 40):
 * Prospecto → Cobertura → Cotización → Aceptación → Orden programada → Instalación → Activación → Cliente activo.
 */
class InstallationService
{
    /** Pasos visibles del flujo (stepper). */
    public const STEPS = [
        'prospecto' => 'Prospecto',
        'cobertura' => 'Cobertura',
        'cotizacion' => 'Cotización',
        'aceptacion' => 'Aceptación',
        'programada' => 'Programada',
        'instalacion' => 'Instalación',
        'activacion' => 'Activación',
        'activo' => 'Cliente activo',
    ];

    /** Índice (0..7) del paso actual del stepper. */
    public function stepIndex(InstallationOrder $order): int
    {
        return match ($order->status) {
            'cotizacion' => $order->quote_amount > 0 || ! empty($order->quote_items) ? 2 : 1,
            'aceptada' => 4,
            'programada' => 5,
            'en_proceso' => 5,
            'instalada' => 6,
            'activada' => 7,
            default => 0,
        };
    }

    public function coordinates(InstallationOrder $order): ?array
    {
        $lat = $order->client?->lat ?? $order->prospect?->lat;
        $lng = $order->client?->lng ?? $order->prospect?->lng;

        return $lat && $lng ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
    }

    public function accept(InstallationOrder $order): void
    {
        $order->update(['status' => 'aceptada', 'quote_accepted_at' => now()]);
    }

    public function schedule(InstallationOrder $order, string $datetime, ?int $technicianId): void
    {
        $order->update([
            'status' => in_array($order->status, ['cotizacion', 'aceptada'], true) ? 'programada' : $order->status,
            'quote_accepted_at' => $order->quote_accepted_at ?? now(),
            'scheduled_at' => $datetime,
            'technician_id' => $technicianId,
        ]);
    }

    public function start(InstallationOrder $order): void
    {
        $order->update(['status' => 'en_proceso', 'started_at' => $order->started_at ?? FieldClock::now()]);
    }

    public function toggleChecklist(InstallationOrder $order, string $key, bool $value): void
    {
        $list = $order->checklist ?? [];
        $list[$key] = $value;
        $order->update(['checklist' => $list]);
    }

    /** Guarda la firma (data URL PNG) en el disco privado de evidencias y marca el punto "firma" del checklist. */
    public function saveSignature(InstallationOrder $order, string $dataUrl, string $signedBy): void
    {
        $png = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $dataUrl), true);
        abort_if($png === false || strlen($png) < 100, 422, 'Firma inválida');
        $path = 'signatures/'.$order->tenant_id.'/'.$order->folio.'-'.now()->format('YmdHis').'.png';
        Evidence::put($path, $png);
        if ($order->signature_path && $order->signature_path !== $path) {
            Evidence::delete($order->signature_path);
        }
        $list = $order->checklist ?? [];
        $list['firma'] = true;
        $order->update(['signature_path' => $path, 'signed_by_name' => $signedBy, 'checklist' => $list]);
        $order->attachments()->updateOrCreate(['kind' => 'firma'], [
            'tenant_id' => $order->tenant_id, 'path' => $path, 'original_name' => 'firma.png', 'mime' => 'image/png',
            'taken_at' => FieldClock::now(), 'uploaded_by' => auth()->id(),
        ]);
    }

    public function complete(InstallationOrder $order): void
    {
        $at = FieldClock::now();
        $order->update(['status' => 'instalada', 'completed_at' => $at, 'started_at' => $order->started_at ?? $at]);
    }

    /**
     * Activación: crea el cliente si hace falta, lo activa en MikroTik, genera el cargo de
     * instalación (salvo promoción de instalación gratis), convierte el prospecto y registra el referido.
     *
     * @return array{client: Client, event: \App\Models\ServiceEvent, charged: float}
     */
    public function activate(InstallationOrder $order, bool $chargeInstallation = true): array
    {
        $order->loadMissing('prospect', 'client', 'plan', 'promotion');
        $prospects = app(ProspectService::class);
        $repeater = $order->repeater_id ? Repeater::find($order->repeater_id) : null;

        $client = DB::transaction(function () use ($order, $prospects, $repeater) {
            $client = $order->client;
            if (! $client && $order->prospect) {
                $client = $prospects->convertToClient($order->prospect, [
                    'plan_id' => $order->plan_id,
                    'repeater_id' => $repeater?->id,
                    'router_device_id' => $repeater?->router_device_id,
                    'technician_id' => $order->technician_id,
                    'installation_date' => now()->toDateString(),
                ]);
            }
            abort_unless($client, 422, 'La orden no tiene prospecto ni cliente asociado.');

            // Datos de servicio de la orden
            $client->forceFill(array_filter([
                'plan_id' => $client->plan_id ?: $order->plan_id,
                'repeater_id' => $client->repeater_id ?: $repeater?->id,
                'router_device_id' => $client->router_device_id ?: $repeater?->router_device_id,
                'zone_id' => $client->zone_id ?: $repeater?->zone_id,
                'technician_id' => $client->technician_id ?: $order->technician_id,
                'installation_date' => $client->installation_date ?? now()->toDateString(),
            ], fn ($v) => $v !== null))->save();

            if ($order->prospect && $order->prospect->status !== 'convertido') {
                $order->prospect->update(['status' => 'convertido', 'converted_at' => now(), 'client_id' => $client->id]);
            }

            $order->update([
                'client_id' => $client->id,
                'status' => 'activada',
                'activated_at' => now(),
                'completed_at' => $order->completed_at ?? now(),
            ]);

            return $client;
        });

        $client->refresh();
        $this->linkCpe($order, $client, $repeater);
        $event = app(ServiceControl::class)->activate($client);

        $charged = 0.0;
        // La cotización ya incluye el descuento de la promoción (instalación gratis => importe 0).
        $amount = (float) $order->quote_amount;
        if ($chargeInstallation && $amount > 0) {
            app(BillingService::class)->createCharge($client, 'instalacion', "Instalación de servicio ({$order->folio})", $amount, now()->addDays((int) tenant()->setting('billing.due_days', 5)));
            $charged = $amount;
        }
        if ($order->promotion) {
            $order->promotion->increment('uses');
        }
        app(BillingService::class)->recalculate($client->fresh());
        Audit::log('activate', "activó la orden de instalación {$order->folio} (cliente #{$client->code})", $order);

        return ['client' => $client, 'event' => $event, 'charged' => $charged];
    }

    /**
     * Completa la cadena cliente → instalación → CPE → repetidor → torre: registra (o vincula) el CPE
     * del cliente con la MAC / IP del cliente y el modelo / serie del equipo descargado del inventario
     * en esta instalación. Si el cliente ya tiene CPE vinculado no crea otro.
     */
    public function linkCpe(InstallationOrder $order, Client $client, ?Repeater $repeater = null): ?NetworkDevice
    {
        $repeater ??= $client->repeater_id ? Repeater::find($client->repeater_id) : null;
        $existing = ($client->cpe_device_id ? NetworkDevice::find($client->cpe_device_id) : null)
            ?? NetworkDevice::query()->where('client_id', $client->id)->whereIn('type', ['cpe', 'antenna'])->whereNotIn('status', ['retired'])->first();

        if ($existing) {
            $existing->forceFill(array_filter([
                'client_id' => $existing->client_id ?: $client->id,
                'repeater_id' => $existing->repeater_id ?: $repeater?->id,
                'tower_id' => $existing->tower_id ?: $repeater?->tower_id,
                'parent_id' => $existing->parent_id ?: $repeater?->ap_device_id,
            ], fn ($v) => $v !== null))->save();
            if ((int) $client->cpe_device_id !== $existing->id) {
                $client->forceFill(['cpe_device_id' => $existing->id])->saveQuietly();
            }

            return $existing;
        }

        // Equipo (antena / CPE) entregado en esta instalación según los movimientos de inventario
        $movement = InventoryMovement::query()->where('installation_order_id', $order->id)
            ->whereHas('item', fn ($q) => $q->whereIn('category', ['cpe', 'antena']))
            ->with('item')->latest('id')->first();
        $item = $movement?->item;

        $device = NetworkDevice::create([
            'type' => 'cpe',
            'name' => 'CPE '.$client->code,
            'manufacturer' => $item?->brand,
            'model' => $item?->model ?: $item?->name,
            'serial' => $movement?->serial,
            'mac' => $client->mac_address ? MonitoringService::mac($client->mac_address) : null,
            'ip' => $client->ip_address,
            'lat' => $client->lat,
            'lng' => $client->lng,
            'location' => $client->address,
            'client_id' => $client->id,
            'repeater_id' => $repeater?->id,
            'tower_id' => $repeater?->tower_id,
            'parent_id' => $repeater?->ap_device_id,
            'inventory_item_id' => $item?->id,
            'responsible_id' => $order->technician_id,
            'signal_dbm' => $order->signal_dbm,
            'installed_at' => now()->toDateString(),
            'status' => 'unknown',
            'notes' => "Registrado al activar la instalación {$order->folio}",
        ]);
        $client->forceFill(['cpe_device_id' => $device->id])->saveQuietly();

        return $device;
    }

    public function cancel(InstallationOrder $order, string $reason): void
    {
        $order->update(['status' => 'cancelada', 'notes' => trim(($order->notes ? $order->notes."\n" : '').'Cancelada: '.$reason)]);
        if ($order->prospect && $order->prospect->status === 'instalacion_pendiente') {
            $order->prospect->update(['status' => $order->prospect->has_coverage === false ? 'sin_cobertura' : 'viable']);
        }
    }

    public function planFor(InstallationOrder $order): ?Plan
    {
        return $order->plan ?? ($order->prospect?->desired_plan_id ? Plan::find($order->prospect->desired_plan_id) : null);
    }
}
