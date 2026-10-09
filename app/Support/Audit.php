<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Bitácora de auditoría. Ejemplo:
 *   Audit::log('suspend', "suspendió cliente #{$client->code}", $client);
 */
class Audit
{
    protected static array $labels = [
        'created' => 'creó',
        'updated' => 'modificó',
        'deleted' => 'eliminó',
    ];

    /** Desactivar durante cargas masivas (seeders / importaciones). */
    public static bool $enabled = true;

    public static function log(string $action, string $description, ?Model $subject = null, array $old = [], array $new = []): void
    {
        if (! static::$enabled) {
            return;
        }
        $user = Auth::user();
        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'tenant_id' => $subject?->tenant_id ?? app(TenantContext::class)->id() ?? $user?->tenant_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Sistema',
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 250) : null,
            'created_at' => now(),
        ]);
    }

    public static function model(string $event, Model $model, array $old, array $new): void
    {
        $label = method_exists($model, 'auditLabel') ? $model->auditLabel() : class_basename($model).' #'.$model->getKey();
        static::log($event, (static::$labels[$event] ?? $event).' '.$label, $model, $old, $new);
    }
}
