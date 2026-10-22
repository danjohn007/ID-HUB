<?php

namespace App\Services\Messaging;

use App\Jobs\DeliverMessage;
use App\Models\Client;
use App\Models\Message;
use App\Models\NotificationTemplate;
use App\Models\PushToken;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Envío de mensajes por WhatsApp, SMS, correo y push con plantillas por WISP.
 *
 *   app(MessageService::class)->notify($client, 'payment_received', ['monto' => money(450), 'folio' => 'REC-1']);
 *
 * Los mensajes se registran en `messages` y se entregan por cola (DeliverMessage).
 * Proveedores configurables por WISP; en modo "simulated" solo se registran.
 */
class MessageService
{
    /** Envía un evento a un cliente por los canales indicados (o los predeterminados del WISP). */
    public function notify(Client $client, string $event, array $vars = [], ?array $channels = null): Collection
    {
        $tenant = $client->tenant;
        $channels ??= $tenant->setting('messaging.default_channels', ['whatsapp']);
        $vars = array_merge($this->clientVars($client), $vars);
        $sent = collect();

        foreach ($channels as $channel) {
            $template = NotificationTemplate::withoutGlobalScopes()
                ->where('tenant_id', $client->tenant_id)
                ->where('event', $event)->where('channel', $channel)->first();
            if ($template && ! $template->active) {
                continue;
            }
            $raw = $template?->body ?? DefaultTemplates::body($event);
            if (str_contains($raw, '{link_pago}') && empty($vars['link_pago'])) {
                $vars['link_pago'] = app(\App\Services\BillingService::class)->paymentLinkFor($client)?->url() ?? '';
            }
            $body = $this->render($raw, $vars);
            $subject = $this->render($template?->subject ?? DefaultTemplates::subject($event), $vars);
            $meta = $channel === 'whatsapp' ? $this->templateMeta($template?->provider_template, $raw, $vars) : null;

            foreach ($this->destinations($client, $channel) as $to) {
                $sent->push($this->queue($tenant, $channel, $to, $body, $subject, $client, $event, null, $meta));
            }
        }

        return $sent;
    }

    /** Mensaje directo (campañas, avisos a personal). */
    public function queue(Tenant $tenant, string $channel, string $to, string $body, ?string $subject = null, ?Client $client = null, ?string $event = null, ?int $campaignId = null, ?array $meta = null): Message
    {
        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client?->id,
            'campaign_id' => $campaignId,
            'channel' => $channel,
            'event' => $event,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'meta' => $meta,
            'status' => 'queued',
        ]);
        DeliverMessage::dispatch($message->id);

        return $message;
    }

    /** Entrega real según proveedor. Llamado por el job DeliverMessage. */
    public function deliver(Message $message): void
    {
        $tenant = Tenant::find($message->tenant_id);
        $cfg = $tenant->settingsFor('messaging');

        try {
            [$status, $providerId] = match ($message->channel) {
                'whatsapp' => $this->sendWhatsApp($cfg, $message),
                'sms' => $this->sendSms($cfg, $message),
                'email' => $this->sendEmail($cfg, $message, $tenant),
                'push' => $this->sendPush($cfg, $message),
                default => ['failed', null],
            };
            $message->update(['status' => $status, 'provider_id' => $providerId, 'sent_at' => now(), 'error' => null]);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    public function render(string $text, array $vars): string
    {
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{'.$k.'}'] = (string) $v;
        }

        return strtr($text, $replace);
    }

    /**
     * Variables de la plantilla en orden de aparición: {nombre} → 1, {plan} → 2...
     * Una variable repetida conserva su número.
     */
    public static function templateVariables(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** Texto con {{1}}, {{2}}... tal como se registra la plantilla en Twilio / WhatsApp. */
    public static function providerText(string $text): string
    {
        $replace = [];
        foreach (self::templateVariables($text) as $i => $name) {
            $replace['{'.$name.'}'] = '{{'.($i + 1).'}}';
        }

        return strtr($text, $replace);
    }

    /**
     * Datos de envío por plantilla aprobada de WhatsApp: nombre de la plantilla en Meta
     * (recordatorio_pago) o Content SID de Twilio (HX...), con sus variables {{1}}, {{2}}...
     * WhatsApp rechaza variables vacías o con saltos de línea: un valor vacío se manda como "-".
     */
    public function templateMeta(?string $template, string $raw, array $vars): ?array
    {
        if (! $template) {
            return null;
        }
        $variables = [];
        foreach (self::templateVariables($raw) as $i => $name) {
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($vars[$name] ?? '')));
            $variables[(string) ($i + 1)] = $value === '' ? '-' : $value;
        }

        return ['template' => $template, 'variables' => $variables];
    }

    /** Nombre de plantilla de Meta: minúsculas, números y guion bajo. */
    public static function isMetaTemplate(?string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9_]{1,512}$/', (string) $value);
    }

    /** Content SID de Twilio: HX + 32 hexadecimales. */
    public static function isTwilioTemplate(?string $value): bool
    {
        return (bool) preg_match('/^HX[0-9a-fA-F]{32}$/', (string) $value);
    }

    public function clientVars(Client $client): array
    {
        $tenant = $client->tenant;
        $openCharge = $client->charges()->open()->orderBy('due_date')->first();

        return [
            'nombre' => $client->first_name,
            'cliente' => $client->full_name,
            'codigo' => $client->code,
            'plan' => $client->plan ? $client->plan->name.' '.$client->plan->speedLabel() : '',
            'monto' => money($openCharge ? $openCharge->balance() : ($client->plan?->price ?? 0)),
            'saldo' => money($client->balance),
            'fecha_limite' => ($openCharge?->due_date ?? $client->next_due_date)?->translatedFormat('d \d\e F') ?? '',
            'empresa' => $tenant->name,
            'telefono_empresa' => $tenant->whatsapp ?: $tenant->phone,
            'zona' => $client->zone?->name ?? '',
            'link_pago' => '',
            'link_comprobante' => '',
            'fecha' => now()->translatedFormat('d \d\e F'),
        ];
    }

    protected function destinations(Client $client, string $channel): array
    {
        return match ($channel) {
            'whatsapp' => array_filter([$this->phone($client->whatsapp ?: $client->phone)]),
            'sms' => array_filter([$this->phone($client->phone ?: $client->whatsapp)]),
            'email' => array_filter([$client->email]),
            'push' => PushToken::withoutGlobalScopes()->where('client_id', $client->id)->pluck('token')->all(),
            default => [],
        };
    }

    /** Normaliza a formato internacional (México por defecto). */
    public function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (! $digits) {
            return null;
        }
        if (strlen($digits) === 10) {
            $digits = '52'.$digits;
        }

        return '+'.$digits;
    }

    protected function sendWhatsApp(array $cfg, Message $m): array
    {
        if (($cfg['whatsapp_provider'] ?? 'simulated') !== 'simulated' && ! Tenant::find($m->tenant_id)?->hasFeature('whatsapp')) {
            throw new \RuntimeException('WhatsApp no está incluido en el plan de ID-Hub de tu empresa.');
        }

        return match ($cfg['whatsapp_provider'] ?? 'simulated') {
            'meta' => $this->metaWhatsApp($cfg, $m),
            'twilio' => $this->twilio($cfg, 'whatsapp:'.$m->to, 'whatsapp:'.($cfg['twilio_whatsapp_from'] ?? ''), $this->twilioContent($m)),
            default => ['simulated', null],
        };
    }

    /**
     * Con plantilla aprobada (Content SID) se manda la plantilla y sus variables: así WhatsApp
     * entrega los avisos fuera de la ventana de 24 h. Sin plantilla se manda el texto libre.
     */
    protected function twilioContent(Message $m): array
    {
        $sid = $m->meta['template'] ?? null;

        return self::isTwilioTemplate($sid)
            ? ['ContentSid' => $sid, 'ContentVariables' => json_encode((object) ($m->meta['variables'] ?? []), JSON_UNESCAPED_UNICODE)]
            : ['Body' => $m->body];
    }

    public static function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version', 'v23.0').'/'.ltrim($path, '/');
    }

    /**
     * WhatsApp Cloud API. Con plantilla aprobada se manda type=template (llega aunque el cliente
     * no haya escrito en 24 h); sin plantilla, texto libre (solo dentro de la ventana de 24 h).
     */
    protected function metaWhatsApp(array $cfg, Message $m): array
    {
        $template = $m->meta['template'] ?? null;
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($m->to, '+')];
        if (self::isMetaTemplate($template)) {
            $parameters = array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($m->meta['variables'] ?? []));
            $payload += ['type' => 'template', 'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $cfg['whatsapp_language'] ?? 'es_MX'],
                'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : null,
            ])];
        } else {
            $payload += ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $m->body]];
        }

        $response = Http::withToken((string) $cfg['whatsapp_token'])->timeout(15)
            ->post(self::graphUrl($cfg['whatsapp_phone_id'].'/messages'), $payload);
        if ($response->failed()) {
            $code = $response->json('error.code');
            $detail = $response->json('error.error_data.details') ?? $response->json('error.message') ?? $response->status();
            throw new \RuntimeException('WhatsApp'.($code ? " $code" : '').': '.(MetaErrors::describe($code) ?? $detail));
        }

        return ['sent', $response->json('messages.0.id')];
    }

    protected function sendSms(array $cfg, Message $m): array
    {
        return ($cfg['sms_provider'] ?? 'simulated') === 'twilio'
            ? $this->twilio($cfg, $m->to, (string) $cfg['twilio_from'], ['Body' => $m->body])
            : ['simulated', null];
    }

    /** $content: ['Body' => ...] o ['ContentSid' => ..., 'ContentVariables' => ...]. */
    protected function twilio(array $cfg, string $to, string $from, array $content): array
    {
        $params = ['To' => $to, 'From' => $from] + $content;
        if ($callback = self::twilioStatusUrl()) {
            $params['StatusCallback'] = $callback;
        }
        $response = Http::asForm()->withBasicAuth((string) $cfg['twilio_sid'], (string) $cfg['twilio_token'])->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['twilio_sid']}/Messages.json", $params);
        if ($response->failed()) {
            $code = $response->json('code');
            throw new \RuntimeException('Twilio'.($code ? " $code" : '').': '.(TwilioErrors::describe($code) ?? $response->json('message') ?? $response->status()));
        }

        return ['sent', $response->json('sid')];
    }

    /**
     * URL a la que Twilio avisa si el mensaje se entregó o falló. Solo con una URL pública https
     * (Twilio no puede llamar a localhost).
     */
    public static function twilioStatusUrl(): ?string
    {
        $url = route('messaging.twilio.status');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true) && ! str_ends_with($host, '.test')
            ? $url
            : null;
    }

    /** Valida Account SID + Auth token consultando la cuenta en Twilio. */
    public function checkTwilio(string $sid, string $token): array
    {
        $response = Http::withBasicAuth($sid, $token)->timeout(15)->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        if ($response->failed()) {
            throw new \RuntimeException($response->status() === 401 ? 'Account SID o Auth token incorrectos.' : 'Twilio respondió '.$response->status().': '.($response->json('message') ?? ''));
        }

        return ['name' => $response->json('friendly_name'), 'type' => $response->json('type'), 'status' => $response->json('status')];
    }

    /** Valida Phone number ID + token consultando el número en Meta. */
    public function checkMeta(string $phoneId, string $token): array
    {
        $response = Http::withToken($token)->timeout(15)
            ->get(self::graphUrl($phoneId), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        if ($response->failed()) {
            $code = $response->json('error.code');
            throw new \RuntimeException(MetaErrors::describe($code) ?? ('Meta respondió '.$response->status().': '.($response->json('error.message') ?? '')));
        }

        return ['number' => $response->json('display_phone_number'), 'name' => $response->json('verified_name'), 'quality' => $response->json('quality_rating')];
    }

    /** URL del webhook de WhatsApp (Meta) del WISP: se captura en la app de Meta. */
    public static function metaWebhookUrl(Tenant $tenant): string
    {
        return route('messaging.meta.webhook', $tenant->id);
    }

    protected function sendEmail(array $cfg, Message $m, Tenant $tenant): array
    {
        if (! ($cfg['email_enabled'] ?? true)) {
            return ['simulated', null];
        }
        Mail::raw($m->body, fn ($mail) => $mail->to($m->to)->subject($m->subject ?: $tenant->name));

        return [in_array(config('mail.default'), ['log', 'array'], true) ? 'simulated' : 'sent', null];
    }

    /**
     * Push por Firebase Cloud Messaging (proyecto de la plataforma) o simulado. Los tokens de Expo
     * (ExponentPushToken[...]) se mandan por Expo. $m->meta['data'] viaja a la app para abrir la
     * pantalla correspondiente (type, id) junto con el evento.
     */
    protected function sendPush(array $cfg, Message $m): array
    {
        if (($cfg['push_provider'] ?? 'simulated') === 'simulated') {
            return ['simulated', null];
        }
        $title = $m->subject ?: (string) Tenant::find($m->tenant_id)?->name;
        $data = ['event' => $m->event] + (array) ($m->meta['data'] ?? []);
        if (self::isExpoToken($m->to)) {
            return $this->expoPush($m, $title, $data);
        }

        try {
            return ['sent', app(FcmClient::class)->send($m->to, $title, $m->body, $data)];
        } catch (FcmException $e) {
            if ($e->invalidToken) {
                PushToken::withoutGlobalScopes()->where('token', $m->to)->delete();
            }
            throw $e;
        }
    }

    public static function isExpoToken(string $token): bool
    {
        return str_starts_with($token, 'ExponentPushToken[') || str_starts_with($token, 'ExpoPushToken[');
    }

    protected function expoPush(Message $m, string $title, array $data): array
    {
        $response = Http::timeout(15)->post('https://exp.host/--/api/v2/push/send', [
            'to' => $m->to, 'title' => $title, 'body' => $m->body, 'sound' => 'default', 'data' => (object) $data,
        ]);
        if ($response->failed() || $response->json('data.status') === 'error') {
            if ($response->json('data.details.error') === 'DeviceNotRegistered') {
                PushToken::withoutGlobalScopes()->where('token', $m->to)->delete();
            }
            throw new \RuntimeException('Push: '.($response->json('data.message') ?? $response->status()));
        }

        return ['sent', $response->json('data.id')];
    }
}
