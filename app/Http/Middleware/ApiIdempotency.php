<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reintentos seguros de la app de campo (bandeja de salida sin conexión).
 *
 * Si la petición trae una clave (encabezado Idempotency-Key o campo client_uuid), la primera
 * respuesta correcta (2xx) se guarda y los reintentos con la misma clave en el mismo WISP la
 * reciben de nuevo sin repetir la acción. Errores (4xx/5xx) no se guardan: se puede reintentar.
 * Cobros y visitas de cobranza además guardan la clave en su propia tabla (índice único).
 */
class ApiIdempotency
{
    public const TABLE = 'api_idempotency_keys';

    public function handle(Request $request, Closure $next): Response
    {
        $key = self::key($request);
        $tenant = tenant();
        if (! $key || ! $tenant || $request->isMethodSafe()) {
            return $next($request);
        }

        $path = mb_substr($request->path(), 0, 190);
        $existing = DB::table(self::TABLE)->where('tenant_id', $tenant->id)->where('key', $key)->first();
        if ($existing && $existing->status === null && now()->diffInMinutes($existing->created_at, true) > 5) {
            // Quedó "en proceso" por un fallo del servidor: se libera la clave.
            DB::table(self::TABLE)->where('id', $existing->id)->delete();
            $existing = null;
        }
        if ($existing) {
            return $this->replay($existing, $request->method(), $path);
        }

        try {
            $id = DB::table(self::TABLE)->insertGetId([
                'tenant_id' => $tenant->id, 'user_id' => $request->user()?->id, 'key' => $key,
                'method' => $request->method(), 'path' => $path, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(DB::table(self::TABLE)->where('tenant_id', $tenant->id)->where('key', $key)->first(), $request->method(), $path);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table(self::TABLE)->where('id', $id)->delete();
            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            DB::table(self::TABLE)->where('id', $id)->update(['status' => $status, 'response' => $response->getContent(), 'updated_at' => now()]);
        } else {
            DB::table(self::TABLE)->where('id', $id)->delete();
        }
        if (random_int(1, 200) === 1) {
            DB::table(self::TABLE)->where('created_at', '<', now()->subDays(14))->delete();
        }

        return $response;
    }

    /** Clave enviada por la app: encabezado Idempotency-Key o campo client_uuid (máx. 64, alfanumérica y guiones). */
    public static function key(Request $request): ?string
    {
        $key = trim((string) ($request->header('Idempotency-Key') ?: $request->input('client_uuid')));

        return $key !== '' && strlen($key) <= 64 && preg_match('/^[A-Za-z0-9_\-:.]+$/', $key) ? $key : null;
    }

    protected function replay(?object $row, string $method, string $path): Response
    {
        if (! $row || $row->status === null) {
            return ApiAuthenticate::error('Esta operación ya se está procesando. Intenta de nuevo en unos segundos.', 409);
        }
        if ($row->method !== $method || $row->path !== $path) {
            return ApiAuthenticate::error('La clave de idempotencia ya se usó en otra operación.', 422);
        }

        return response((string) $row->response, (int) $row->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}
