<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro de tokens de notificaciones push (sección 34) para staff y clientes.
 * Un token de dispositivo pertenece a un solo dueño: si ya existía se reasigna.
 */
class PushTokenController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->check($request, [
            'token' => 'required|string|min:10|max:255',
            'platform' => 'nullable|in:android,ios,web',
        ]);
        $client = $request->attributes->get('api_client');
        $owner = $client
            ? ['client_id' => $client->id, 'user_id' => null]
            : ['client_id' => null, 'user_id' => $this->apiUser()->id];

        // El mismo dispositivo pudo estar registrado en otra cuenta / WISP.
        PushToken::withoutGlobalScopes()->where('token', $data['token'])->where('tenant_id', '!=', tenant()->id)->delete();
        $push = PushToken::query()->updateOrCreate(
            ['token' => $data['token']],
            $owner + ['platform' => $data['platform'] ?? null]
        );

        return $this->ok(['data' => ['id' => $push->id, 'platform' => $push->platform]], 'Dispositivo registrado para notificaciones.', 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $this->check($request, ['token' => 'required|string|max:255']);
        $client = $request->attributes->get('api_client');
        PushToken::query()->where('token', $data['token'])
            ->when($client, fn ($q) => $q->where('client_id', $client->id), fn ($q) => $q->where('user_id', $this->apiUser()->id))
            ->delete();

        return $this->ok([], 'Dispositivo eliminado de notificaciones.');
    }
}
