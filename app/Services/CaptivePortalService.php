<?php

namespace App\Services;

use App\Models\CaptiveAd;
use App\Models\CaptiveSession;
use App\Models\CaptiveVoucher;
use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Tenant;
use App\Services\MikroTik\MikroTikService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Portal cautivo público (sección 28): autenticación por usuario/contraseña, código de cliente
 * o voucher; registro de sesiones; publicidad local con impresiones y clics.
 *
 * El portal valida al usuario y devuelve las credenciales del hotspot; la página de conexión
 * las envía al MikroTik (link-login-only) para que abra la navegación.
 */
class CaptivePortalService
{
    /** Estados de servicio con los que un cliente puede navegar. */
    public const ALLOWED_STATUSES = ['activo', 'moroso', 'en_revision'];

    /**
     * Valida el acceso. Devuelve ['ok' => bool, 'error' => ?string, 'username' => ?string, 'password' => ?string, 'client' => ?Client].
     */
    public function authenticate(Tenant $tenant, string $method, array $input, ?string $mac, ?string $ip): array
    {
        $result = match ($method) {
            'usuario' => $this->byUser(trim((string) ($input['username'] ?? '')), (string) ($input['password'] ?? '')),
            'codigo' => $this->byCode(trim((string) ($input['code'] ?? ''))),
            'voucher' => $this->byVoucher($tenant, trim((string) ($input['voucher'] ?? '')), $mac),
            default => ['ok' => false, 'error' => 'Método de acceso no disponible.'],
        };

        CaptiveSession::create([
            'method' => $method,
            'identifier' => mb_substr(trim((string) ($input['username'] ?? $input['code'] ?? $input['voucher'] ?? '')), 0, 190) ?: null,
            'client_id' => ($result['client'] ?? null)?->id,
            'mac' => $mac ? strtoupper(mb_substr($mac, 0, 17)) : null,
            'ip' => $ip,
            'success' => $result['ok'],
        ]);

        return $result + ['client' => null, 'username' => null, 'password' => null, 'error' => null];
    }

    protected function clientCredentials(Client $client): array
    {
        return [(string) ($client->pppoe_user ?: $client->code), (string) ($client->pppoe_password ?: $client->code)];
    }

    protected function serviceError(Client $client): ?string
    {
        if (in_array($client->service_status, self::ALLOWED_STATUSES, true)) {
            return null;
        }

        return in_array($client->service_status, ['suspendido', 'cancelado'], true)
            ? 'Tu servicio está suspendido. Realiza tu pago o comunícate con nosotros para reactivarlo.'
            : 'Tu servicio aún no está activo. Comunícate con nosotros.';
    }

    protected function byUser(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Escribe tu usuario y contraseña.'];
        }
        $client = Client::query()->where(fn ($q) => $q->where('pppoe_user', $username)->orWhere('code', $username))->first();
        if (! $client) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }
        [$user, $pass] = $this->clientCredentials($client);
        if (! hash_equals($pass, $password)) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.', 'client' => $client];
        }
        if ($error = $this->serviceError($client)) {
            return ['ok' => false, 'error' => $error, 'client' => $client];
        }

        return ['ok' => true, 'client' => $client, 'username' => $user, 'password' => $pass];
    }

    protected function byCode(string $code): array
    {
        if ($code === '') {
            return ['ok' => false, 'error' => 'Escribe tu código de cliente.'];
        }
        $client = Client::query()->where('code', strtoupper($code))->first();
        if (! $client) {
            return ['ok' => false, 'error' => 'No encontramos ese código de cliente.'];
        }
        if ($error = $this->serviceError($client)) {
            return ['ok' => false, 'error' => $error, 'client' => $client];
        }
        [$user, $pass] = $this->clientCredentials($client);

        return ['ok' => true, 'client' => $client, 'username' => $user, 'password' => $pass];
    }

    protected function byVoucher(Tenant $tenant, string $code, ?string $mac): array
    {
        if ($code === '') {
            return ['ok' => false, 'error' => 'Escribe el código de tu ficha.'];
        }
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code));
        $mac = $mac ? strtoupper($mac) : null;

        return DB::transaction(function () use ($tenant, $code, $mac) {
            $voucher = CaptiveVoucher::query()->where('code', $code)->lockForUpdate()->first();
            if (! $voucher) {
                return ['ok' => false, 'error' => 'La ficha no es válida.'];
            }
            if ($voucher->status === 'expired' || ($voucher->status === 'available' && $voucher->expires_at?->isPast())) {
                if ($voucher->status !== 'expired') {
                    $voucher->update(['status' => 'expired']);
                }

                return ['ok' => false, 'error' => 'La ficha ya expiró.'];
            }
            if ($voucher->status === 'used') {
                // Reconexión del mismo dispositivo mientras dure el tiempo de la ficha
                $active = $voucher->used_at && $voucher->used_at->copy()->addMinutes($voucher->duration_minutes)->isFuture();
                if (! $active || ($voucher->mac && $mac && $voucher->mac !== $mac)) {
                    return ['ok' => false, 'error' => 'La ficha ya fue utilizada.'];
                }

                return ['ok' => true, 'username' => $voucher->code, 'password' => $voucher->code];
            }

            $voucher->update(['status' => 'used', 'used_at' => now(), 'mac' => $mac]);
            $this->provisionVoucher($tenant, $voucher);

            return ['ok' => true, 'username' => $voucher->code, 'password' => $voucher->code];
        });
    }

    /** Crea el usuario de hotspot de la ficha en los MikroTik del WISP que dan servicio por hotspot. */
    public function provisionVoucher(Tenant $tenant, CaptiveVoucher $voucher): void
    {
        $attrs = [
            'name' => $voucher->code, 'password' => $voucher->code,
            'limit-uptime' => $voucher->duration_minutes.'m', 'comment' => 'Ficha '.($voucher->batch ?: 'portal'),
        ];
        if ($voucher->data_limit_mb) {
            $attrs['limit-bytes-total'] = (string) ($voucher->data_limit_mb * 1024 * 1024);
        }
        $routers = NetworkDevice::query()->where('type', 'mikrotik')->where('service_method', 'hotspot')->where('status', '!=', 'retired')->get();
        foreach ($routers as $router) {
            try {
                MikroTikService::for($router)->transport()->add('/ip/hotspot/user', $attrs);
            } catch (\Throwable $e) {
                Log::warning("Portal cautivo: no se pudo crear la ficha {$voucher->code} en {$router->name}: {$e->getMessage()}", ['tenant' => $tenant->id]);
            }
        }
    }

    // ------------------------------------------------------------------ Publicidad

    /** Anuncios vigentes elegidos al azar según su peso; registra la impresión. */
    public function pickAds(int $limit = 3): Collection
    {
        $ads = CaptiveAd::query()->current()->get();
        $picked = collect();
        while ($ads->isNotEmpty() && $picked->count() < $limit) {
            $roll = random_int(1, max(1, (int) $ads->sum('weight')));
            foreach ($ads as $i => $ad) {
                $roll -= max(1, (int) $ad->weight);
                if ($roll <= 0) {
                    $picked->push($ad);
                    $ads->forget($i);
                    break;
                }
            }
        }
        foreach ($picked as $ad) {
            $this->track($ad, 'impressions');
        }

        return $picked;
    }

    public function track(CaptiveAd $ad, string $column): void
    {
        abort_unless(in_array($column, ['impressions', 'clicks'], true), 500);
        $ad->increment($column);
        $today = now()->toDateString();
        $updated = DB::table('captive_ad_stats')->where('captive_ad_id', $ad->id)->where('date', $today)->increment($column);
        if (! $updated) {
            DB::table('captive_ad_stats')->insertOrIgnore(['captive_ad_id' => $ad->id, 'date' => $today, 'impressions' => 0, 'clicks' => 0]);
            DB::table('captive_ad_stats')->where('captive_ad_id', $ad->id)->where('date', $today)->increment($column);
        }
    }

    /**
     * URL de login del MikroTik (link-login-only) solo si apunta a la red local del hotspot
     * (IP privada o el nombre DNS configurado), para no enviar credenciales a sitios externos.
     */
    public function safeLoginUrl(Tenant $tenant, ?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $dns = strtolower(trim((string) $tenant->setting('captive.hotspot_dns', '')));
        $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $isPrivateIp || ($dns !== '' && $host === $dns) ? $url : null;
    }
}
