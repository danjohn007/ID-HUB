<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\Client;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticación de la API v1:
 *  - Personal del WISP (email + contraseña) → token con las habilidades de su rol.
 *  - Cliente final (ID de cliente o usuario PPPoE + contraseña del portal) → token "client".
 */
class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:120',
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->ok([], 'Las credenciales no son válidas.', 401);
        }
        if (! $user->active) {
            return $this->ok([], 'Tu usuario está desactivado.', 403);
        }
        $tenant = $user->tenant;
        if (! $user->isSuperadmin() && (! $tenant || ! $tenant->isActive())) {
            return $this->ok([], 'La cuenta de tu empresa está suspendida. Contacta al administrador.', 403);
        }

        $abilities = $user->isSuperadmin() ? ['*'] : array_values(array_unique(array_merge(['staff'], config("permissions.roles.{$user->role}", []))));
        $token = $user->createToken($data['device_name'], $abilities);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        app(TenantContext::class)->set($tenant);
        auth()->setUser($user);
        Audit::log('login', 'inició sesión en la API ('.$data['device_name'].')', $user);

        return $this->ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $abilities,
            'user' => new UserResource($user),
            'tenant' => $tenant ? self::tenantInfo($tenant) : null,
        ], 'Sesión iniciada.');
    }

    public function clientLogin(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'identifier' => 'required|string|max:80',
            'password' => 'required|string|max:120',
            'device_name' => 'required|string|max:120',
            'tenant' => 'nullable|string|max:80',
        ]);

        $identifier = trim($data['identifier']);
        $query = Client::withoutGlobalScopes()->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('code', $identifier)->orWhere('pppoe_user', $identifier));
        if (! empty($data['tenant'])) {
            $tenant = Tenant::query()->where('slug', $data['tenant'])->orWhere('id', ctype_digit($data['tenant']) ? (int) $data['tenant'] : 0)->first();
            if (! $tenant) {
                return $this->ok([], 'La empresa indicada no existe.', 422);
            }
            $query->where('tenant_id', $tenant->id);
        }

        $matches = $query->limit(10)->get()->filter(fn (Client $c) => self::checkClientPassword($c, $data['password']));
        if ($matches->isEmpty()) {
            return $this->ok([], 'El ID de cliente o la contraseña no son válidos.', 401);
        }
        if ($matches->count() > 1) {
            return $this->ok([], 'Hay más de una cuenta con esos datos. Indica la empresa en el campo "tenant".', 422);
        }

        /** @var Client $client */
        $client = $matches->first();
        $tenant = Tenant::find($client->tenant_id);
        if (! $tenant || ! $tenant->isActive()) {
            return $this->ok([], 'El servicio de tu proveedor no está disponible por el momento.', 403);
        }
        if (! $tenant->hasFeature('app_cliente') && ! $tenant->hasFeature('api')) {
            return $this->ok([], 'Tu proveedor no tiene habilitada la app del cliente.', 403);
        }
        if (in_array($client->service_status, ['cancelado', 'prospecto'], true)) {
            return $this->ok([], 'Tu cuenta no tiene un servicio activo. Contacta a tu proveedor.', 403);
        }

        $token = $client->createToken($data['device_name'], ['client']);

        return $this->ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => ['client'],
            'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->full_name, 'first_name' => $client->first_name],
            'tenant' => self::tenantInfo($tenant),
            'must_set_password' => $client->portal_password === null,
        ], 'Sesión iniciada.');
    }

    /** Revoca el token actual (staff o cliente) y, opcionalmente, su token push. */
    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('api_token');
        if ($push = $request->input('push_token')) {
            PushToken::query()->where('token', $push)->delete();
        }
        $token?->delete();

        return $this->ok([], 'Sesión cerrada.');
    }

    public function me(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');
        if ($client = $request->attributes->get('api_client')) {
            return $this->ok([
                'type' => 'client',
                'client' => ['id' => $client->id, 'code' => $client->code, 'name' => $client->full_name, 'email' => $client->email, 'phone' => $client->phone],
                'tenant' => self::tenantInfo(Tenant::find($client->tenant_id)),
                'abilities' => $token?->abilities,
            ]);
        }
        $user = $this->apiUser();

        return $this->ok([
            'type' => 'staff',
            'user' => new UserResource($user),
            'tenant' => tenant() ? self::tenantInfo(tenant()) : null,
            'abilities' => $token?->abilities,
            'permissions' => $user->isSuperadmin() ? ['*'] : config("permissions.roles.{$user->role}", []),
        ]);
    }

    /** portal_password (hash o texto); si es null se acepta la contraseña PPPoE. */
    public static function checkClientPassword(Client $client, string $password): bool
    {
        $stored = $client->portal_password;
        if ($stored !== null && $stored !== '') {
            $isHash = (Hash::info($stored)['algoName'] ?? 'unknown') !== 'unknown';

            return $isHash ? Hash::check($password, $stored) : hash_equals((string) $stored, $password);
        }
        try {
            $pppoe = $client->pppoe_password;
        } catch (\Throwable) {
            return false;
        }

        return $pppoe !== null && $pppoe !== '' && hash_equals((string) $pppoe, $password);
    }

    public static function tenantInfo(?Tenant $tenant): ?array
    {
        if (! $tenant) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'logo_url' => $tenant->logoUrl(),
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,
        ];
    }
}
