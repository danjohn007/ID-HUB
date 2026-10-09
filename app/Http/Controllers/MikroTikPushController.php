<?php

namespace App\Http\Controllers;

use App\Models\NetworkDevice;
use App\Models\Tenant;
use App\Services\MikroTik\PushTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Endpoints públicos del agente push de MikroTik (sin sesión; autenticados por push_token).
 *   POST /mikrotik/push/{token}/report    telemetría JSON del equipo
 *   GET  /mikrotik/push/{token}/commands  comandos pendientes como script .rsc (text/plain)
 */
class MikroTikPushController extends Controller
{
    protected function device(string $token): NetworkDevice
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{20,64}$/', $token), 404);
        $device = NetworkDevice::withoutGlobalScopes()->where('push_token', $token)->where('type', 'mikrotik')->whereNull('deleted_at')->first();
        abort_unless($device && $device->api_mode === 'push', 404);
        $tenant = Tenant::find($device->tenant_id);
        abort_unless($tenant && $tenant->isActive(), 403);

        return $device;
    }

    protected function throttle(string $token, string $action): void
    {
        $key = "mikrotik-push:{$action}:".substr(sha1($token), 0, 16);
        abort_if(RateLimiter::tooManyAttempts($key, 20), 429, 'Demasiadas solicitudes');
        RateLimiter::hit($key, 60);
    }

    public function report(Request $request, string $token)
    {
        $this->throttle($token, 'report');
        $device = $this->device($token);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 2 * 1024 * 1024, 413, 'Reporte demasiado grande');
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return response("ERROR: JSON inválido\n", 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $data['_received_at'] = now()->toDateTimeString();
        $data['_ip'] = $request->ip();
        Cache::put(PushTransport::reportKey($device->id), $data, now()->addDay());

        $res = is_array($data['resource'] ?? null) ? $data['resource'] : [];
        if ($res && array_is_list($res)) {
            $res = $res[0] ?? [];
        }
        $total = (float) ($res['total-memory'] ?? 0);
        $free = (float) ($res['free-memory'] ?? 0);
        $device->forceFill(array_filter([
            'status' => 'online',
            'last_seen_at' => now(),
            'last_sync_at' => now(),
            'last_error' => null,
            'cpu' => isset($res['cpu-load']) ? (float) $res['cpu-load'] : null,
            'memory' => $total > 0 ? round(($total - $free) / $total * 100, 1) : null,
            'uptime' => isset($res['uptime']) ? (string) $res['uptime'] : null,
            'firmware' => isset($res['version']) ? 'RouterOS '.$res['version'] : null,
        ], fn ($v) => $v !== null) + ['last_error' => null])->saveQuietly();

        return response("OK\n", 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function commands(string $token)
    {
        $this->throttle($token, 'commands');
        $device = $this->device($token);
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response(PushTransport::pullScript($device), 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
