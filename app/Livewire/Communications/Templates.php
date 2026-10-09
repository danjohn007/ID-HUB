<?php

namespace App\Livewire\Communications;

use App\Livewire\Concerns\Toasts;
use App\Models\Client;
use App\Models\NotificationTemplate;
use App\Services\Messaging\DefaultTemplates;
use App\Services\Messaging\MessageCatalog;
use App\Services\Messaging\MessageService;
use App\Support\Audit;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Plantillas de mensajes por evento × canal (secciones 6.2, 34 y 35): texto, asunto, activación,
 * variables, vista previa con un cliente de ejemplo, restaurar texto por defecto y envío de prueba.
 */
#[Title('Plantillas de mensajes')]
class Templates extends Component
{
    use Toasts;

    #[Url(as: 'evento')]
    public string $event = 'reminder';

    #[Url(as: 'canal')]
    public string $channel = 'whatsapp';

    public array $form = ['active' => true, 'subject' => '', 'body' => ''];

    public ?int $sampleId = null;

    public string $testTo = '';

    public bool $dirty = false;

    public function mount(): void
    {
        if (! array_key_exists($this->event, config('wisp.message_events'))) {
            $this->event = 'reminder';
        }
        if (! array_key_exists($this->channel, config('wisp.channels'))) {
            $this->channel = 'whatsapp';
        }
        $this->sampleId = Client::query()->whereIn('service_status', Client::BILLABLE)->where('balance', '>', 0)->orderBy('id')->value('id')
            ?? Client::query()->orderBy('id')->value('id');
        $this->loadForm();
    }

    public function select(string $event, ?string $channel = null): void
    {
        if (! array_key_exists($event, config('wisp.message_events'))) {
            return;
        }
        $this->event = $event;
        if ($channel && array_key_exists($channel, config('wisp.channels'))) {
            $this->channel = $channel;
        }
        $this->loadForm();
    }

    public function updatedChannel(): void
    {
        $this->loadForm();
    }

    public function updatedForm(): void
    {
        $this->dirty = true;
    }

    protected function template(): ?NotificationTemplate
    {
        return NotificationTemplate::query()->where('event', $this->event)->where('channel', $this->channel)->first();
    }

    protected function loadForm(): void
    {
        $tpl = $this->template();
        $this->form = [
            'active' => $tpl ? (bool) $tpl->active : true,
            'subject' => $tpl?->subject ?? DefaultTemplates::subject($this->event),
            'body' => $tpl?->body ?? DefaultTemplates::body($this->event),
        ];
        $this->dirty = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.body' => 'required|string|max:1600',
            'form.subject' => 'nullable|string|max:150',
            'form.active' => 'boolean',
        ], [], ['form.body' => 'mensaje', 'form.subject' => 'asunto'])['form'];

        $tpl = NotificationTemplate::updateOrCreate(
            ['event' => $this->event, 'channel' => $this->channel],
            ['body' => $data['body'], 'subject' => $data['subject'] ?: null, 'active' => (bool) $data['active']]
        );
        Audit::log('updated', 'actualizó la plantilla '.config("wisp.message_events.{$this->event}").' ('.config("wisp.channels.{$this->channel}").')', $tpl);
        $this->dirty = false;
        $this->toast('Plantilla guardada');
    }

    public function toggleActive(): void
    {
        $tpl = $this->template();
        $active = ! ($tpl ? $tpl->active : true);
        NotificationTemplate::updateOrCreate(
            ['event' => $this->event, 'channel' => $this->channel],
            ['active' => $active, 'body' => $tpl?->body ?? $this->form['body'], 'subject' => $tpl?->subject ?? ($this->form['subject'] ?: null)]
        );
        $this->form['active'] = $active;
        $this->toast($active ? 'Plantilla activada' : 'Plantilla desactivada: este aviso no se enviará por '.config("wisp.channels.{$this->channel}"), $active ? 'success' : 'warning');
    }

    public function restoreDefault(): void
    {
        $this->form['body'] = DefaultTemplates::body($this->event);
        $this->form['subject'] = DefaultTemplates::subject($this->event);
        $this->dirty = true;
        $this->toast('Texto por defecto restaurado. Guarda para aplicarlo.', 'info');
    }

    public function sendTest(MessageService $messages): void
    {
        if ($this->channel === 'push') {
            $this->toast('La prueba de push requiere la app del cliente instalada', 'warning');

            return;
        }
        $rule = $this->channel === 'email' ? 'required|email|max:150' : ['required', 'string', 'regex:/^[\d\s\+\-\(\)]{10,20}$/'];
        $this->validate(['testTo' => $rule], [], ['testTo' => $this->channel === 'email' ? 'correo' : 'teléfono']);

        $to = $this->channel === 'email' ? $this->testTo : $messages->phone($this->testTo);
        $client = $this->sampleId ? Client::with(['plan', 'zone'])->find($this->sampleId) : null;
        $vars = $this->sampleVars($messages, $client);
        $m = $messages->queue(tenant(), $this->channel, $to, '[PRUEBA] '.$messages->render($this->form['body'], $vars),
            $messages->render((string) $this->form['subject'], $vars) ?: tenant()->name, null, 'test');
        $m->refresh();
        $this->toast($m->status === 'failed' ? 'Falló el envío: '.$m->error : 'Mensaje de prueba enviado a '.$to.($m->status === 'simulated' ? ' (modo simulado)' : ''), $m->status === 'failed' ? 'error' : 'success');
    }

    protected function sampleVars(MessageService $messages, ?Client $client): array
    {
        $vars = $client ? $messages->clientVars($client) : [];

        return array_merge($vars, [
            'link_pago' => MessageCatalog::sampleLink(),
            'folio' => $vars['folio'] ?? 'T-001258',
            'tecnico' => 'Juan Pérez',
            'descripcion' => 'Descripción del aviso',
        ]);
    }

    public function render(MessageService $messages)
    {
        $templates = NotificationTemplate::query()->get()->groupBy('event');
        $client = $this->sampleId ? Client::with(['plan', 'zone'])->find($this->sampleId) : null;
        $vars = $this->sampleVars($messages, $client);

        return view('livewire.communications.templates', [
            'templates' => $templates,
            'preview' => $messages->render((string) $this->form['body'], $vars),
            'previewSubject' => $messages->render((string) $this->form['subject'], $vars),
            'isCustom' => ($tpl = $this->template()) && $tpl->body !== DefaultTemplates::body($this->event),
            'exists' => (bool) $tpl,
            'samples' => Client::query()->whereIn('service_status', Client::BILLABLE)->orderByDesc('balance')->limit(30)->get()
                ->mapWithKeys(fn ($c) => [$c->id => "#{$c->code} · {$c->full_name} (".money($c->balance).')']),
            'defaultChannels' => (array) tenant()->setting('messaging.default_channels', ['whatsapp']),
        ]);
    }
}
