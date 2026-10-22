<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging (API HTTP v1) para las apps móviles (app de campo y app del cliente).
 *
 * Es de la plataforma, no de cada WISP: un solo proyecto de Firebase y su cuenta de servicio
 * (JSON descargado de Firebase → Configuración del proyecto → Cuentas de servicio), en
 * FIREBASE_CREDENTIALS o storage/app/private/firebase.json.
 *
 *   app(FcmClient::class)->send($token, 'Nuevo ticket asignado', 'T-000123 · Sin Internet', ['type' => 'ticket', 'id' => '123']);
 */
class FcmClient
{
    protected const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    protected ?array $credentials = null;

    public function __construct(protected ?string $path = null)
    {
        $this->path ??= (string) config('services.firebase.credentials');
    }

    /** JSON de la cuenta de servicio o null si falta o no es válido. */
    public function credentials(): ?array
    {
        if ($this->credentials === null && $this->path !== '' && is_file($this->path)) {
            $json = json_decode((string) file_get_contents($this->path), true);
            if (is_array($json) && ! empty($json['project_id']) && ! empty($json['client_email']) && ! empty($json['private_key'])) {
                $this->credentials = $json;
            }
        }

        return $this->credentials;
    }

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    public function projectId(): ?string
    {
        return $this->credentials()['project_id'] ?? null;
    }

    /**
     * Envía una notificación a un dispositivo y devuelve el id del mensaje en Firebase.
     * Los valores de $data deben ser texto (FCM no acepta números ni arreglos).
     */
    public function send(string $token, string $title, string $body, array $data = []): string
    {
        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'android' => ['priority' => 'HIGH', 'notification' => ['sound' => 'default', 'channel_id' => 'avisos']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];
        $data = array_map('strval', array_filter($data, fn ($v) => $v !== null && $v !== ''));
        if ($data) {
            $message['data'] = $data;
        }

        $response = Http::withToken($this->accessToken())->timeout(15)
            ->post('https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send', ['message' => $message]);
        if ($response->failed()) {
            $status = (string) $response->json('error.status');
            $code = collect((array) $response->json('error.details'))->pluck('errorCode')->filter()->first();
            $detail = (string) $response->json('error.message', $response->status());
            // Token desinstalado, de otro proyecto o mal formado: el dispositivo ya no recibirá avisos.
            $invalid = in_array($code, ['UNREGISTERED', 'SENDER_ID_MISMATCH'], true)
                || ($status === 'INVALID_ARGUMENT' && str_contains(strtolower($detail), 'registration token'));
            throw new FcmException('Push: '.match (true) {
                $invalid => 'el dispositivo ya no está registrado (se dio de baja).',
                $status === 'UNAUTHENTICATED' || $status === 'PERMISSION_DENIED' => 'Firebase rechazó la cuenta de servicio: revisa que el JSON sea de este proyecto y tenga el rol de Firebase Cloud Messaging.',
                default => $detail,
            }, $invalid);
        }

        return (string) $response->json('name');
    }

    /** Valida la cuenta de servicio obteniendo un token de acceso de Google. */
    public function check(): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('No se encontró el JSON de la cuenta de servicio de Firebase en el servidor.');
        }
        Cache::forget($this->cacheKey());
        $this->accessToken();

        return ['project' => $this->projectId(), 'email' => $this->credentials()['client_email']];
    }

    /** Token OAuth 2.0 (JWT firmado con la llave de la cuenta de servicio); se reutiliza ~50 min. */
    public function accessToken(): string
    {
        $cred = $this->credentials() ?? throw new \RuntimeException('Push: Firebase no está configurado en el servidor (FIREBASE_CREDENTIALS).');

        return Cache::remember($this->cacheKey(), now()->addMinutes(50), function () use ($cred) {
            $uri = $cred['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64url(json_encode(['iss' => $cred['client_email'], 'scope' => self::SCOPE, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600])),
            ];
            $key = openssl_pkey_get_private($cred['private_key']);
            if (! $key || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('Push: la llave privada del JSON de Firebase no es válida.');
            }
            $segments[] = self::base64url($signature);

            $response = Http::asForm()->timeout(15)->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
            if ($response->failed() || ! $response->json('access_token')) {
                throw new \RuntimeException('Push: Google rechazó la cuenta de servicio ('.($response->json('error_description') ?? $response->json('error') ?? $response->status()).').');
            }

            return (string) $response->json('access_token');
        });
    }

    protected function cacheKey(): string
    {
        return 'fcm-token:'.md5((string) ($this->credentials()['client_email'] ?? ''));
    }

    protected static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
