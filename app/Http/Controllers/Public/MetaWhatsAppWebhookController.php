<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Services\Messaging\MetaErrors;
use App\Services\Messaging\MetaTemplates;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook de WhatsApp Cloud API (Meta), uno por WISP: /webhooks/whatsapp/{tenant}.
 *  - GET: verificación al darlo de alta en Meta (hub.verify_token = token del WISP).
 *  - POST (se valida X-Hub-Signature-256 con el secreto de la app de Meta del WISP):
 *      · messages: estados de entrega (sent, delivered, read, failed) y mensajes que escriben
 *        los clientes (quedan en la bitácora / conversación como "Recibido").
 *      · message_template_status_update: aprobación o rechazo de plantillas.
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
                $value = (array) ($change['value'] ?? []);
                if (($change['field'] ?? 'messages') === 'message_template_status_update') {
                    $this->applyTemplateStatus($tenant, $value);

                    continue;
                }
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $this->applyStatus($tenant, (array) $status);
                }
                $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                foreach ((array) ($value['messages'] ?? []) as $incoming) {
                    $this->storeIncoming($tenant, (array) $incoming, $names);
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

    /** Mensaje que escribió un cliente: abre la ventana de 24 h para responderle con texto libre. */
    protected function storeIncoming(int $tenantId, array $incoming, Collection $names): void
    {
        $id = (string) ($incoming['id'] ?? '');
        $from = (string) preg_replace('/\D+/', '', (string) ($incoming['from'] ?? ''));
        // Meta reintenta el webhook si no responde a tiempo: no se duplica.
        if ($id === '' || $from === '' || Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider_id', $id)->exists()) {
            return;
        }
        $type = (string) ($incoming['type'] ?? 'text');
        $caption = trim((string) ($incoming[$type]['caption'] ?? ''));
        $body = match ($type) {
            'text' => $incoming['text']['body'] ?? '',
            'button' => $incoming['button']['text'] ?? '',
            'interactive' => $incoming['interactive']['button_reply']['title'] ?? $incoming['interactive']['list_reply']['title'] ?? '',
            'image' => trim('[Imagen] '.$caption),
            'video' => trim('[Video] '.$caption),
            'document' => trim('[Documento] '.($incoming['document']['filename'] ?? $caption)),
            'audio' => '[Nota de voz]',
            'location' => '[Ubicación] '.($incoming['location']['latitude'] ?? '').','.($incoming['location']['longitude'] ?? ''),
            'sticker' => '[Sticker]',
            'reaction' => '[Reacción] '.($incoming['reaction']['emoji'] ?? ''),
            default => '['.$type.']',
        };

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'client_id' => $this->clientByPhone($tenantId, $from)?->id,
            'channel' => 'whatsapp',
            'event' => 'inbound',
            'to' => '+'.$from,
            'subject' => $names->get($from),
            'body' => mb_substr((string) $body, 0, 4000) ?: '[Mensaje]',
            'status' => 'received',
            'provider_id' => $id,
            'sent_at' => isset($incoming['timestamp']) ? now()->setTimestamp((int) $incoming['timestamp']) : now(),
        ]);
    }

    /** Cliente del WISP por WhatsApp o teléfono (se comparan los últimos 10 dígitos). */
    protected function clientByPhone(int $tenantId, string $digits): ?Client
    {
        $last10 = substr($digits, -10);
        $tail = substr($digits, -4);
        $normalize = fn (?string $phone) => substr((string) preg_replace('/\D+/', '', (string) $phone), -10);

        return Client::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('whatsapp', 'like', "%$tail")->orWhere('phone', 'like', "%$tail"))
            ->orderByRaw("CASE WHEN service_status = 'cancelado' THEN 1 ELSE 0 END")->limit(50)->get()
            ->first(fn (Client $c) => in_array($last10, [$normalize($c->whatsapp), $normalize($c->phone)], true));
    }

    /** Meta avisa cuando aprueba, rechaza, pausa o deshabilita una plantilla. */
    protected function applyTemplateStatus(int $tenantId, array $value): void
    {
        $name = (string) ($value['message_template_name'] ?? '');
        $status = strtoupper((string) ($value['event'] ?? ''));
        if ($name === '' || $status === '') {
            return;
        }
        $reason = (string) ($value['reason'] ?? '');
        NotificationTemplate::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('channel', 'whatsapp')->where('provider_template', $name)
            ->update([
                'provider_status' => mb_substr($status, 0, 20),
                'provider_note' => $reason !== '' && $reason !== 'NONE' ? mb_substr(MetaTemplates::reasonText($reason), 0, 190) : null,
            ]);
    }
}
