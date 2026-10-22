<?php

namespace App\Services;

use App\Models\Alert;
use Illuminate\Database\Eloquent\Model;

/**
 * Alertas inteligentes con deduplicación: una misma condición (dedupe_key) solo
 * mantiene una alerta abierta; al desaparecer la condición se resuelve.
 *
 *   app(AlertService::class)->raise('repeater_down', 'critical', 'Repetidor R-04 fuera de servicio', '85 clientes afectados', $repeater);
 */
class AlertService
{
    public function raise(string $type, string $severity, string $title, ?string $message = null, ?Model $subject = null, array $data = [], ?string $dedupeKey = null): Alert
    {
        $dedupeKey ??= $type.':'.($subject ? $subject->getMorphClass().':'.$subject->getKey() : md5($title));

        $existing = Alert::query()->open()->where('dedupe_key', $dedupeKey)->first();
        if ($existing) {
            $existing->update(['title' => $title, 'message' => $message, 'severity' => $severity, 'data' => $data ?: $existing->data]);

            return $existing;
        }

        $alert = Alert::create([
            'tenant_id' => $subject?->tenant_id ?? tenant()?->id,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'dedupe_key' => $dedupeKey,
            'status' => 'open',
            'data' => $data ?: null,
        ]);

        // Las alertas también pueden disparar reglas de automatización
        $event = match (true) {
            in_array($type, ['repeater_saturated', 'device_offline', 'low_stock', 'over_stock', 'mass_disconnect', 'abnormal_signal', 'data_cap_exceeded'], true) => $type,
            str_starts_with($type, 'anomaly_') => 'anomaly_detected',
            default => null,
        };
        if ($event && $subject) {
            // Los datos escalares de la alerta (consumo, señal, tipo de anomalía...) quedan disponibles para las condiciones
            $context = ['alert' => $alert, 'alert_type' => $type] + array_filter($data, fn ($v) => is_scalar($v));
            app(AutomationEngine::class)->fire($event, $subject, $context);
        }

        return $alert;
    }

    public function resolve(string $dedupeKey): int
    {
        return Alert::query()->open()->where('dedupe_key', $dedupeKey)
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function resolveFor(string $type, Model $subject): int
    {
        return $this->resolve($type.':'.$subject->getMorphClass().':'.$subject->getKey());
    }
}
