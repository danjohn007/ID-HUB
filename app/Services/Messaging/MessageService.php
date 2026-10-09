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

            foreach ($this->destinations($client, $channel) as $to) {
                $sent->push($this->queue($tenant, $channel, $to, $body, $subject, $client, $event));
            }
        }

        return $sent;
    }

    /** Mensaje directo (campañas, avisos a personal). */
    public function queue(Tenant $tenant, string $channel, string $to, string $body, ?string $subject = null, ?Client $client = null, ?string $event = null, ?int $campaignId = null): Message
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
        return match ($cfg['whatsapp_provider'] ?? 'simulated') {
            'meta' => $this->metaWhatsApp($cfg, $m),
            'twilio' => $this->twilio($cfg, 'whatsapp:'.$m->to, 'whatsapp:'.($cfg['twilio_whatsapp_from'] ?? ''), $m->body),
            default => ['simulated', null],
        };
    }

    protected function metaWhatsApp(array $cfg, Message $m): array
    {
        $response = Http::withToken((string) $cfg['whatsapp_token'])->timeout(15)
            ->post('https://graph.facebook.com/v20.0/'.$cfg['whatsapp_phone_id'].'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($m->to, '+'),
                'type' => 'text',
                'text' => ['body' => $m->body],
            ]);
        if ($response->failed()) {
            throw new \RuntimeException('WhatsApp: '.($response->json('error.message') ?? $response->status()));
        }

        return ['sent', $response->json('messages.0.id')];
    }

    protected function sendSms(array $cfg, Message $m): array
    {
        return ($cfg['sms_provider'] ?? 'simulated') === 'twilio'
            ? $this->twilio($cfg, $m->to, (string) $cfg['twilio_from'], $m->body)
            : ['simulated', null];
    }

    protected function twilio(array $cfg, string $to, string $from, string $body): array
    {
        $response = Http::asForm()->withBasicAuth((string) $cfg['twilio_sid'], (string) $cfg['twilio_token'])->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['twilio_sid']}/Messages.json", ['To' => $to, 'From' => $from, 'Body' => $body]);
        if ($response->failed()) {
            throw new \RuntimeException('Twilio: '.($response->json('message') ?? $response->status()));
        }

        return ['sent', $response->json('sid')];
    }

    protected function sendEmail(array $cfg, Message $m, Tenant $tenant): array
    {
        if (! ($cfg['email_enabled'] ?? true)) {
            return ['simulated', null];
        }
        Mail::raw($m->body, fn ($mail) => $mail->to($m->to)->subject($m->subject ?: $tenant->name));

        return [in_array(config('mail.default'), ['log', 'array'], true) ? 'simulated' : 'sent', null];
    }

    /** Push vía Expo Push API (apps móviles Expo) o simulado. */
    protected function sendPush(array $cfg, Message $m): array
    {
        if (($cfg['push_provider'] ?? 'simulated') !== 'expo') {
            return ['simulated', null];
        }
        $response = Http::timeout(15)->post('https://exp.host/--/api/v2/push/send', [
            'to' => $m->to, 'title' => $m->subject, 'body' => $m->body, 'sound' => 'default',
        ]);
        if ($response->failed() || $response->json('data.status') === 'error') {
            throw new \RuntimeException('Push: '.($response->json('data.message') ?? $response->status()));
        }

        return ['sent', $response->json('data.id')];
    }
}
