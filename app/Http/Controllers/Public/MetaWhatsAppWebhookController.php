<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Messaging\MetaErrors;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook de WhatsApp Cloud API (Meta), uno por WISP: /webhooks/whatsapp/{tenant}.
 *  - GET: verificación al darlo de alta en Meta (hub.verify_token = token del WISP).
 *  - POST: estados de entrega (sent, delivered, read, failed); se valida X-Hub-Signature-256
 *    con el secreto de la app de Meta del WISP.
 */
class MetaWhatsAppWebhookController extends Controller
{
    public function verify(Request $request, int $tenant): Response
    {
        $cfg = Tenant::find($tenant)?->settingsFor('messaging') ?? [];
        $token = (string) ($cfg['whatsapp_verify_token'] ?? '');

        // PHP convierte "hub.mode" en "hub_mode" al leer la query
        return $request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))
            ? response((string) $request->query('hub_challenge'), 200)
            : response('Token de verificación incorrecto', 403);
    }

    public function handle(Request $request, int $tenant): Response
    {
        $cfg = Tenant::find($tenant)?->settingsFor('messaging') ?? [];
        $secret = (string) ($cfg['whatsapp_app_secret'] ?? '');
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response('Firma inválida', 403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $this->applyStatus($tenant, (array) $status);
                }
            }
        }

        return response('', 200);
    }

    protected function applyStatus(int $tenantId, array $status): void
    {
        $message = Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider_id', $status['id'] ?? '')->first();
        if (! $message) {
            return;
        }
        $state = $status['status'] ?? '';
        if ($state === 'failed') {
            $error = $status['errors'][0] ?? [];
            $code = $error['code'] ?? null;
            $detail = MetaErrors::describe($code) ?? ($error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? 'el mensaje no se entregó.');
            $message->update(['status' => 'failed', 'error' => mb_substr('WhatsApp'.($code ? " $code" : '').': '.$detail, 0, 250)]);
        } elseif (in_array($state, ['delivered', 'read'], true) && $message->status !== 'failed') {
            $message->update(['status' => 'delivered', 'error' => null]);
        }
        // "sent": ya está como Enviado. Los avisos pueden llegar en desorden: nunca se regresa un Entregado.
    }
}
