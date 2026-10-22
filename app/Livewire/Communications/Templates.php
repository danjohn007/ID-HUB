<?php

namespace App\Livewire\Communications;

use App\Livewire\Concerns\Toasts;
use App\Models\Client;
use App\Models\NotificationTemplate;
use App\Services\Messaging\DefaultTemplates;
use App\Services\Messaging\MessageCatalog;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MetaTemplates;
use App\Services\Messaging\StaffPush;
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

    public array $form = ['active' => true, 'subject' => '', 'body' => '', 'provider_template' => ''];

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

    protected function whatsappProvider(): string
    {
        return (string) tenant()->setting('messaging.whatsapp_provider', 'simulated');
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
            'provider_template' => $tpl?->provider_template ?? '',
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
            'form.provider_template' => ['nullable', 'string', 'max:80', ...match ($this->whatsappProvider()) {
                'twilio' => ['regex:/^HX[0-9a-fA-F]{32}$/'],
                'meta' => ['regex:/^[a-z0-9_]+$/'],
                default => [],
            }],
        ], ['form.provider_template.regex' => $this->whatsappProvider() === 'twilio'
                ? 'El Content SID empieza con HX seguido de 32 caracteres (cópialo de Twilio → Content Template Builder).'
                : 'El nombre de la plantilla de Meta solo lleva minúsculas, números y guion bajo (p. ej. recordatorio_pago).'],
            ['form.body' => 'mensaje', 'form.subject' => 'asunto', 'form.provider_template' => 'plantilla aprobada'])['form'];

        $before = $this->template();
        $providerTemplate = $this->channel === 'whatsapp' ? (trim((string) $data['provider_template']) ?: null) : null;
        $tpl = NotificationTemplate::updateOrCreate(
            ['event' => $this->event, 'channel' => $this->channel],
            ['body' => $data['body'], 'subject' => $data['subject'] ?: null, 'active' => (bool) $data['active'],
                'provider_template' => $providerTemplate]
                // Otra plantilla de Meta: su estado se desconoce hasta consultarlo.
                + ($before && $before->provider_template !== $providerTemplate ? ['provider_status' => null, 'provider_note' => null] : [])
        );
        Audit::log('updated', 'actualizó la plantilla '.config("wisp.message_events.{$this->event}").' ('.config("wisp.channels.{$this->channel}").')', $tpl);
        $this->dirty = false;
        $textChanged = $before && $before->body !== $tpl->body && $tpl->provider_status;
        $this->toast($textChanged
            ? 'Plantilla guardada. Cambiaste el texto: la plantilla aprobada en WhatsApp conserva el anterior; envíala de nuevo a revisión con otro nombre si cambiaron las variables.'
            : 'Plantilla guardada', $textChanged ? 'warning' : 'success');
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
            $this->sendPushTest($messages);

            return;
        }
        $rule = $this->channel === 'email' ? 'required|email|max:150' : ['required', 'string', 'regex:/^[\d\s\+\-\(\)]{10,20}$/'];
        $this->validate(['testTo' => $rule], [], ['testTo' => $this->channel === 'email' ? 'correo' : 'teléfono']);

        $to = $this->channel === 'email' ? $this->testTo : $messages->phone($this->testTo);
        $client = $this->sampleId ? Client::with(['plan', 'zone'])->find($this->sampleId) : null;
        $vars = $this->sampleVars($messages, $client);
        // Con plantilla aprobada la prueba manda la plantilla (sin el prefijo [PRUEBA], que no forma parte de ella).
        $meta = $this->channel === 'whatsapp' ? $messages->templateMeta(trim((string) ($this->form['provider_template'] ?? '')) ?: null, $this->form['body'], $vars) : null;
        $m = $messages->queue(tenant(), $this->channel, $to, ($meta ? '' : '[PRUEBA] ').$messages->render($this->form['body'], $vars),
            $messages->render((string) $this->form['subject'], $vars) ?: tenant()->name, null, 'test', null, $meta);
        $m->refresh();
        $this->toast($m->status === 'failed' ? 'Falló el envío: '.$m->error : 'Mensaje de prueba enviado a '.$to.($m->status === 'simulated' ? ' (modo simulado)' : ''), $m->status === 'failed' ? 'error' : 'success');
    }

    /** La prueba de push llega a los dispositivos donde el usuario inició sesión en la app de campo. */
    protected function sendPushTest(MessageService $messages): void
    {
        $client = $this->sampleId ? Client::with(['plan', 'zone'])->find($this->sampleId) : null;
        $vars = $this->sampleVars($messages, $client);
        $n = app(StaffPush::class)->toUser(auth()->user(), $messages->render((string) $this->form['subject'], $vars) ?: tenant()->name,
            $messages->render($this->form['body'], $vars), ['type' => 'test']);
        if (! $n) {
            $this->toast('No tienes dispositivos registrados: inicia sesión con tu usuario en la app de campo y acepta las notificaciones', 'warning');

            return;
        }
        $last = \App\Models\Message::query()->where('channel', 'push')->where('event', 'staff')->latest('id')->first();
        $this->toast($last?->status === 'failed' ? 'Falló el envío: '.$last->error : 'Notificación de prueba enviada a '.$n.' '.($n === 1 ? 'dispositivo' : 'dispositivos').($last?->status === 'simulated' ? ' (modo simulado)' : ''), $last?->status === 'failed' ? 'error' : 'success');
    }

    protected function sampleVars(MessageService $messages, ?Client $client): array
    {
        $vars = $client ? $messages->clientVars($client) : [];

        return array_merge($vars, [
            'link_pago' => MessageCatalog::sampleLink(),
            'link_comprobante' => rtrim(config('app.url'), '/').'/api/v1/receipts/1024?signature=ejemplo',
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
            'whatsappProvider' => $this->whatsappProvider(),
            'whatsappLanguage' => tenant()->setting('messaging.whatsapp_language', 'es_MX'),
            'providerText' => MessageService::providerText((string) $this->form['body']),
            'providerVars' => MessageService::templateVariables((string) $this->form['body']),
            'providerWarning' => MetaTemplates::warning((string) $this->form['body']),
            'metaStatus' => MetaTemplates::statusLabel($tpl?->provider_status),
            'metaNote' => $tpl?->provider_note,
            'metaReady' => filled(tenant()->setting('messaging.whatsapp_waba_id')),
        ]);
    }

    // ------------------------------------------------------------ Meta (WhatsApp Cloud API)

    /** Envía la plantilla de WhatsApp de este evento a revisión de Meta (con el texto guardado). */
    public function submitToMeta(MetaTemplates $meta): void
    {
        if ($this->dirty) {
            $this->toast('Guarda la plantilla antes de enviarla a Meta', 'warning');

            return;
        }
        $name = trim((string) ($this->form['provider_template'] ?? ''));
        if ($name !== '' && ! MessageService::isMetaTemplate($name)) {
            $this->addError('form.provider_template', 'El nombre de la plantilla de Meta solo lleva minúsculas, números y guion bajo (p. ej. recordatorio_pago).');

            return;
        }
        try {
            $tpl = $meta->submit(tenant(), $this->event, $name ?: null);
        } catch (\Throwable $e) {
            $this->toast('Meta: '.$e->getMessage(), 'error');

            return;
        }
        Audit::log('updated', 'envió a Meta la plantilla de WhatsApp '.$tpl->provider_template, $tpl);
        $this->loadForm();
        [$label] = MetaTemplates::statusLabel($tpl->provider_status) ?? ['Enviada'];
        $this->toast('Plantilla "'.$tpl->provider_template.'" en Meta: '.$label, $tpl->provider_status === 'REJECTED' ? 'error' : 'success');
    }

    /** Envía a revisión todas las plantillas de WhatsApp activas que aún no tienen plantilla en Meta. */
    public function submitAllToMeta(MetaTemplates $meta): void
    {
        $existing = NotificationTemplate::query()->where('channel', 'whatsapp')->get()->keyBy('event');
        $sent = 0;
        $errors = [];
        foreach (array_keys(config('wisp.message_events')) as $event) {
            $tpl = $existing->get($event);
            if ($tpl && (! $tpl->active || $tpl->provider_status)) {
                continue;
            }
            try {
                $meta->submit(tenant(), $event);
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = config("wisp.message_events.$event").': '.$e->getMessage();
                if (str_contains($e->getMessage(), 'Configuración → Mensajería')) {
                    break;
                }
            }
        }
        $this->loadForm();
        if ($errors) {
            $this->toast(($sent ? "$sent plantillas enviadas. " : '').'No se enviaron '.count($errors).': '.implode(' · ', array_slice($errors, 0, 3)), 'error');
        } else {
            $this->toast($sent ? "$sent plantillas enviadas a revisión de Meta. La aprobación suele tardar de minutos a 24 h." : 'Todas las plantillas ya están en Meta', $sent ? 'success' : 'info');
        }
    }

    /** Consulta en Meta el estado de todas las plantillas de WhatsApp del WISP. */
    public function syncMeta(MetaTemplates $meta): void
    {
        try {
            $n = $meta->sync(tenant());
        } catch (\Throwable $e) {
            $this->toast('Meta: '.$e->getMessage(), 'error');

            return;
        }
        $this->loadForm();
        $this->toast($n ? "Estado de $n plantillas actualizado" : 'No hay plantillas de WhatsApp asignadas todavía', $n ? 'success' : 'info');
    }
}
