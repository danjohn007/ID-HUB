<?php

namespace App\Livewire\Communications;

use App\Jobs\DeliverMessage;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Client;
use App\Models\Message;
use App\Models\NotificationTemplate;
use App\Services\BillingService;
use App\Services\Messaging\CampaignService;
use App\Services\Messaging\DefaultTemplates;
use App\Services\Messaging\MessageCatalog;
use App\Services\Messaging\MessageService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bitácora de mensajes de todos los canales (WhatsApp, SMS, correo, push), reenvío de fallidos,
 * mensaje directo a un cliente con plantilla o texto libre y vista de conversación por cliente.
 */
#[Title('Mensajes')]
class Messages extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'canal', except: '')]
    public string $filterChannel = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'evento', except: '')]
    public string $filterEvent = '';

    #[Url(as: 'desde', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'hasta', except: '')]
    public string $filterTo = '';

    protected array $sortable = ['created_at', 'channel', 'status'];

    // Detalle
    public ?int $detailId = null;

    public bool $showDetail = false;

    // Nuevo mensaje
    public bool $showCompose = false;

    public string $clientSearch = '';

    public array $compose = ['client_id' => null, 'channel' => 'whatsapp', 'mode' => 'template', 'event' => 'pending', 'subject' => '', 'body' => ''];

    // Conversación
    public bool $showChat = false;

    public ?int $chatClientId = null;

    public string $chatChannel = 'whatsapp';

    public string $chatBody = '';

    public function mount(): void
    {
        if ($id = request()->integer('cliente')) {
            $this->openChat($id);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('filterChannel', 'filterStatus', 'filterEvent', 'filterFrom', 'filterTo', 'search');
        $this->resetPage();
    }

    protected function query()
    {
        $term = trim($this->search);

        return Message::query()
            ->with(['client:id,code,first_name,last_name', 'campaign:id,name'])
            ->when($this->filterChannel, fn ($q) => $q->where('channel', $this->filterChannel))
            ->when($this->filterStatus, fn ($q) => $this->filterStatus === 'sent'
                ? $q->whereIn('status', CampaignService::SENT_STATUSES)
                : $q->where('status', $this->filterStatus))
            ->when($this->filterEvent, fn ($q) => $q->where('event', $this->filterEvent))
            ->when($this->filterFrom, fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->filterFrom)->startOfDay()))
            ->when($this->filterTo, fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->filterTo)->endOfDay()))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('to', 'like', "%$term%")
                    ->orWhereIn('client_id', Client::query()->select('id')->search($term));
            }));
    }

    // ------------------------------------------------------------ Detalle / reenvío

    public function openDetail(int $id): void
    {
        $this->detailId = $id;
        $this->showDetail = true;
    }

    public function resend(int $id): void
    {
        $message = Message::findOrFail($id);
        if ($message->status !== 'failed') {
            $this->toast('Solo se pueden reenviar mensajes fallidos', 'warning');

            return;
        }
        $message->update(['status' => 'queued', 'error' => null]);
        DeliverMessage::dispatch($message->id);
        $message->refresh();
        $this->toast($message->status === 'failed' ? 'El reenvío volvió a fallar: '.$message->error : 'Mensaje reenviado', $message->status === 'failed' ? 'error' : 'success');
    }

    public function resendAllFailed(): void
    {
        $ids = $this->query()->where('status', 'failed')->limit(500)->pluck('id');
        foreach ($ids as $id) {
            Message::whereKey($id)->update(['status' => 'queued', 'error' => null]);
            DeliverMessage::dispatch($id);
        }
        $this->toast($ids->count().' mensajes reenviados');
    }

    // ------------------------------------------------------------ Nuevo mensaje

    public function openCompose(?int $clientId = null): void
    {
        $this->resetValidation();
        $this->compose = ['client_id' => $clientId, 'channel' => 'whatsapp', 'mode' => 'template', 'event' => 'pending', 'subject' => '', 'body' => ''];
        $this->clientSearch = '';
        $this->loadTemplate();
        $this->showCompose = true;
    }

    public function selectClient(int $id): void
    {
        $this->compose['client_id'] = $id;
        $this->clientSearch = '';
    }

    public function updatedCompose($value, $key): void
    {
        if (in_array($key, ['event', 'channel', 'mode']) && $this->compose['mode'] === 'template') {
            $this->loadTemplate();
        }
        if ($key === 'mode' && $value === 'free') {
            $this->compose['body'] = '';
        }
    }

    protected function loadTemplate(): void
    {
        $tpl = NotificationTemplate::query()->where('event', $this->compose['event'])->where('channel', $this->compose['channel'])->first();
        $this->compose['body'] = $tpl?->body ?? DefaultTemplates::body($this->compose['event']);
        $this->compose['subject'] = $tpl?->subject ?? DefaultTemplates::subject($this->compose['event']);
    }

    public function insertVariable(string $var): void
    {
        $this->compose['body'] = rtrim($this->compose['body']).' '.$var;
    }

    public function send(MessageService $messages): void
    {
        $this->validate([
            'compose.client_id' => 'required|integer',
            'compose.channel' => 'required|in:'.implode(',', array_keys(config('wisp.channels'))),
            'compose.body' => 'required|string|max:1600',
            'compose.subject' => 'nullable|string|max:150',
        ], [], ['compose.client_id' => 'cliente', 'compose.body' => 'mensaje', 'compose.channel' => 'canal']);

        $client = Client::with(['plan', 'zone'])->findOrFail($this->compose['client_id']);
        $event = $this->compose['mode'] === 'template' ? $this->compose['event'] : 'manual';
        $count = $this->queueFor($messages, $client, $this->compose['channel'], $this->compose['body'], $this->compose['subject'] ?: null, $event);
        if ($count === 0) {
            $this->addError('compose.channel', 'El cliente no tiene dato de contacto para este canal.');

            return;
        }
        $this->showCompose = false;
        $this->toast('Mensaje enviado a '.$client->full_name);
    }

    /** Renderiza y encola para todos los destinos del cliente en el canal. */
    protected function queueFor(MessageService $messages, Client $client, string $channel, string $text, ?string $subject, string $event): int
    {
        $destinations = $this->destinations($messages, $client, $channel);
        if (! $destinations) {
            return 0;
        }
        $vars = $messages->clientVars($client);
        if (str_contains($text, '{link_pago}')) {
            $vars['link_pago'] = app(BillingService::class)->paymentLinkFor($client)?->url() ?? '';
        }
        $vars['descripcion'] ??= '';
        $body = $messages->render($text, $vars);
        foreach ($destinations as $to) {
            $messages->queue($client->tenant, $channel, $to, $body, $subject ? $messages->render($subject, $vars) : tenant()->name, $client, $event);
        }

        return count($destinations);
    }

    protected function destinations(MessageService $messages, Client $client, string $channel): array
    {
        return match ($channel) {
            'whatsapp' => array_filter([$messages->phone($client->whatsapp ?: $client->phone)]),
            'sms' => array_filter([$messages->phone($client->phone ?: $client->whatsapp)]),
            'email' => array_filter([$client->email]),
            'push' => \App\Models\PushToken::query()->where('client_id', $client->id)->pluck('token')->all(),
            default => [],
        };
    }

    // ------------------------------------------------------------ Conversación

    public function openChat(int $clientId): void
    {
        $this->chatClientId = $clientId;
        $this->chatBody = '';
        $this->showChat = true;
        $this->showDetail = false;
    }

    public function sendChat(MessageService $messages): void
    {
        $this->validate(['chatBody' => 'required|string|max:1600'], [], ['chatBody' => 'mensaje']);
        $client = Client::with(['plan', 'zone'])->findOrFail($this->chatClientId);
        if (! $this->queueFor($messages, $client, $this->chatChannel, $this->chatBody, null, 'manual')) {
            $this->addError('chatBody', 'El cliente no tiene dato de contacto para este canal.');

            return;
        }
        $this->chatBody = '';
        $this->dispatch('chat-sent');
    }

    // ------------------------------------------------------------ Render

    public function render(MessageService $messages)
    {
        $sent = CampaignService::SENT_STATUSES;
        $byChannel = fn ($q) => $q->selectRaw('channel, COUNT(*) n')->groupBy('channel')->pluck('n', 'channel');
        $today = $byChannel(Message::query()->whereIn('status', $sent)->where('created_at', '>=', today()));
        $month = $byChannel(Message::query()->whereIn('status', $sent)->where('created_at', '>=', now()->startOfMonth()));
        $failedMonth = Message::query()->where('status', 'failed')->where('created_at', '>=', now()->startOfMonth())->count();
        $queued = Message::query()->where('status', 'queued')->count();

        $previewClient = $this->compose['client_id'] ? Client::with(['plan', 'zone'])->find($this->compose['client_id']) : null;
        $preview = null;
        if ($this->showCompose && $this->compose['body'] !== '') {
            $vars = $previewClient ? $messages->clientVars($previewClient) : [];
            $vars['link_pago'] = MessageCatalog::sampleLink();
            $vars['descripcion'] ??= '';
            $preview = $messages->render($this->compose['body'], $vars);
        }

        $chatClient = $this->showChat && $this->chatClientId ? Client::find($this->chatClientId) : null;

        return view('livewire.communications.messages', [
            'messagesList' => $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage),
            'kpi' => [
                'today' => $today, 'month' => $month, 'failed' => $failedMonth, 'queued' => $queued,
                'failed_rate' => ($tot = $month->sum() + $failedMonth) > 0 ? round($failedMonth / $tot * 100, 1) : 0,
            ],
            'detail' => $this->showDetail && $this->detailId ? Message::with(['client', 'campaign'])->find($this->detailId) : null,
            'clientResults' => $this->showCompose && strlen(trim($this->clientSearch)) >= 2
                ? Client::query()->search($this->clientSearch)->orderBy('code')->limit(8)->get() : collect(),
            'previewClient' => $previewClient,
            'preview' => $preview,
            'chatClient' => $chatClient,
            'chat' => $chatClient ? Message::query()->where('client_id', $chatClient->id)->latest()->limit(80)->get()->reverse()->values() : collect(),
            'events' => MessageCatalog::events(),
        ]);
    }
}
