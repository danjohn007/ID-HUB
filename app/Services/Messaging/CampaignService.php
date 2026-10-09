<?php

namespace App\Services\Messaging;

use App\Models\Campaign;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Message;
use App\Models\Plan;
use App\Models\PushToken;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\BillingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones masivas (sección 36): resolución de segmentos, envío por lotes
 * y contadores de resultados.
 *
 *   $service->start($campaign);          // marca "enviando" y envía el primer lote
 *   $service->process($campaign, 200);   // siguiente lote (scheduler / sondeo de la vista)
 *   $service->refreshCounts($campaign);  // enviados / fallidos según estado de mensajes
 *
 * El envío es incremental e idempotente: solo se crean mensajes para clientes del
 * segmento que aún no tienen mensaje de esta campaña (apto para hosting compartido).
 */
class CampaignService
{
    /** Segmentos que requieren un valor (zona, repetidor o plan). */
    public const VALUE_SEGMENTS = ['zone', 'repeater', 'plan'];

    public const SENT_STATUSES = ['sent', 'delivered', 'simulated'];

    public function __construct(protected MessageService $messages) {}

    /** Clientes del segmento (sin filtrar por dato de contacto). */
    public function segmentQuery(string $segment, mixed $value = null): Builder
    {
        $base = Client::query()->whereNotIn('service_status', ['cancelado', 'prospecto']);

        return match ($segment) {
            'zone' => $base->where('zone_id', $value ?: 0),
            'repeater' => $base->where('repeater_id', $value ?: 0),
            'plan' => $base->where('plan_id', $value ?: 0),
            'morosos' => $base->where(function ($q) {
                $q->where('service_status', 'moroso')->orWhere(function ($w) {
                    $w->whereIn('service_status', Client::BILLABLE)->where('balance', '>', 0)
                        ->whereIn('id', Charge::query()->select('client_id')->open()->where('due_date', '<', now()->toDateString()));
                });
            }),
            'activos' => $base->where('service_status', 'activo'),
            'nuevos' => $base->where('installation_date', '>=', now()->subDays(30)->toDateString()),
            'suspendidos' => $base->where('service_status', 'suspendido'),
            default => $base,
        };
    }

    /** Clientes del segmento con dato de contacto para el canal. */
    public function recipientsQuery(string $segment, mixed $value, string $channel): Builder
    {
        $q = $this->segmentQuery($segment, $value);
        $filled = fn (string $col) => fn ($w) => $w->whereNotNull($col)->where($col, '!=', '');

        return match ($channel) {
            'whatsapp', 'sms' => $q->where(fn ($w) => $w->where($filled('whatsapp'))->orWhere($filled('phone'))),
            'email' => $q->where($filled('email')),
            'push' => $q->whereIn('id', PushToken::query()->select('client_id')->whereNotNull('client_id')),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** ['segment' => total del segmento, 'reachable' => con contacto en el canal]. */
    public function counts(string $segment, mixed $value, string $channel): array
    {
        if (in_array($segment, self::VALUE_SEGMENTS, true) && ! $value) {
            return ['segment' => 0, 'reachable' => 0];
        }

        return [
            'segment' => $this->segmentQuery($segment, $value)->count(),
            'reachable' => $this->recipientsQuery($segment, $value, $channel)->count(),
        ];
    }

    public function segmentLabel(Campaign $campaign): string
    {
        $label = config("wisp.campaign_segments.{$campaign->segment}", $campaign->segment);
        $value = match ($campaign->segment) {
            'zone' => Zone::find($campaign->segment_value)?->name,
            'repeater' => ($r = Repeater::find($campaign->segment_value)) ? $r->code.' · '.$r->name : null,
            'plan' => Plan::find($campaign->segment_value)?->name,
            default => null,
        };

        return $value ? "$label: $value" : $label;
    }

    /** Muestra para vista previa: primer cliente del segmento con contacto (o del segmento). */
    public function sampleClient(string $segment, mixed $value, string $channel): ?Client
    {
        return $this->recipientsQuery($segment, $value, $channel)->orderBy('id')->first()
            ?? $this->segmentQuery($segment, $value)->orderBy('id')->first();
    }

    /** Texto final para un cliente (variables + enlace de pago si la plantilla lo usa). */
    public function renderFor(Client $client, string $text): string
    {
        $vars = $this->messages->clientVars($client);
        if (str_contains($text, '{link_pago}')) {
            $vars['link_pago'] = app(BillingService::class)->paymentLinkFor($client)?->url() ?? '';
        }
        $vars['descripcion'] ??= '';

        return $this->messages->render($text, $vars);
    }

    /** Inicia el envío: fija destinatarios y procesa el primer lote. */
    public function start(Campaign $campaign, int $firstBatch = 100): int
    {
        $reachable = $this->recipientsQuery($campaign->segment, $campaign->segment_value, $campaign->channel)->count();
        $campaign->update([
            'status' => 'sending',
            'recipients_count' => $reachable,
            'sent_at' => $campaign->sent_at ?? now(),
        ]);

        return $this->process($campaign, $firstBatch);
    }

    /**
     * Envía el siguiente lote. Devuelve los mensajes creados. Protegido con un candado
     * para que el scheduler y la vista no dupliquen envíos.
     */
    public function process(Campaign $campaign, int $limit = 200): int
    {
        if ($campaign->status !== 'sending') {
            return 0;
        }

        $created = (int) Cache::lock('campaign-send:'.$campaign->id, 120)->get(function () use ($campaign, $limit) {
            $tenant = $campaign->tenant ?? \App\Models\Tenant::find($campaign->tenant_id);
            $done = Message::withoutGlobalScopes()->select('client_id')->where('campaign_id', $campaign->id)->whereNotNull('client_id');
            $clients = $this->recipientsQuery($campaign->segment, $campaign->segment_value, $campaign->channel)
                ->whereNotIn('id', $done)->with(['plan', 'zone'])->orderBy('id')->limit($limit)->get();

            $count = 0;
            foreach ($clients as $client) {
                $client->setRelation('tenant', $tenant);
                $body = $this->renderFor($client, $campaign->body);
                $subject = $campaign->subject ? $this->renderFor($client, $campaign->subject) : $campaign->name;
                foreach ($this->destinations($client, $campaign->channel) as $to) {
                    $this->messages->queue($tenant, $campaign->channel, $to, $body, $subject, $client, 'campaign', $campaign->id);
                    $count++;
                }
            }

            if ($clients->count() < $limit) {
                $campaign->forceFill(['status' => 'sent'])->save();
            }

            return $count;
        });

        $this->refreshCounts($campaign);

        return $created;
    }

    /** Recalcula enviados / fallidos a partir del estado de sus mensajes. */
    public function refreshCounts(Campaign $campaign): void
    {
        $stats = Message::withoutGlobalScopes()->where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        if ($stats->isEmpty()) {
            return; // campañas sin mensajes registrados (p. ej. históricas) conservan sus contadores
        }
        $sent = (int) collect(self::SENT_STATUSES)->sum(fn ($s) => $stats[$s] ?? 0);
        $campaign->forceFill([
            'sent_count' => $sent,
            'failed_count' => (int) ($stats['failed'] ?? 0),
            'recipients_count' => max((int) $campaign->recipients_count, (int) $stats->sum()),
        ])->saveQuietly();
    }

    /** Pendientes por crear (destinatarios aún sin mensaje). */
    public function pending(Campaign $campaign): int
    {
        if ($campaign->status !== 'sending') {
            return 0;
        }
        $done = Message::withoutGlobalScopes()->select('client_id')->where('campaign_id', $campaign->id)->whereNotNull('client_id');

        return $this->recipientsQuery($campaign->segment, $campaign->segment_value, $campaign->channel)->whereNotIn('id', $done)->count();
    }

    /** Reintenta los mensajes fallidos de la campaña. */
    public function retryFailed(Campaign $campaign): int
    {
        $ids = Message::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', 'failed')->pluck('id');
        foreach ($ids as $id) {
            Message::withoutGlobalScopes()->whereKey($id)->update(['status' => 'queued', 'error' => null]);
            \App\Jobs\DeliverMessage::dispatch($id);
        }
        $this->refreshCounts($campaign);

        return $ids->count();
    }

    /** Ejecutado cada minuto por el scheduler para un WISP (dentro de su contexto). */
    public function tick(int $budget = 400): array
    {
        $started = 0;
        foreach (Campaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get() as $c) {
            $this->start($c, 0);
            $started++;
        }

        $sent = 0;
        foreach (Campaign::query()->where('status', 'sending')->orderBy('id')->get() as $c) {
            if ($budget - $sent <= 0) {
                break;
            }
            $sent += $this->process($c, max(1, $budget - $sent));
        }

        // Contadores de campañas recientes (los mensajes pueden cambiar de estado después)
        Campaign::query()->where('status', 'sent')->where('updated_at', '>=', now()->subDays(2))->get()
            ->each(fn ($c) => $this->refreshCounts($c));

        return ['started' => $started, 'messages' => $sent];
    }

    protected function destinations(Client $client, string $channel): array
    {
        return match ($channel) {
            'whatsapp' => array_filter([$this->messages->phone($client->whatsapp ?: $client->phone)]),
            'sms' => array_filter([$this->messages->phone($client->phone ?: $client->whatsapp)]),
            'email' => array_filter([$client->email]),
            'push' => PushToken::withoutGlobalScopes()->where('client_id', $client->id)->pluck('token')->all(),
            default => [],
        };
    }
}
