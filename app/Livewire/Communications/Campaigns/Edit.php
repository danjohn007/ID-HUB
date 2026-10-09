<?php

namespace App\Livewire\Communications\Campaigns;

use App\Livewire\Concerns\Toasts;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\Messaging\CampaignService;
use App\Services\Messaging\MessageCatalog;
use App\Services\Messaging\MessageService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Alta / edición de campaña masiva (sección 36): segmento, canal, mensaje con variables,
 * vista previa con un destinatario de ejemplo, conteo en vivo y envío inmediato o programado.
 */
class Edit extends Component
{
    use Toasts;

    public ?Campaign $campaign = null;

    public array $form = [];

    /** now | schedule */
    public string $when = 'now';

    public ?int $sampleId = null;

    public function mount(?Campaign $campaign = null): void
    {
        $this->campaign = $campaign?->exists ? $campaign : null;
        if ($this->campaign && ! in_array($this->campaign->status, ['draft', 'scheduled'])) {
            $this->redirectRoute('communications.campaigns.show', $this->campaign, navigate: true);

            return;
        }

        $this->form = $this->campaign
            ? [
                'name' => $this->campaign->name, 'channel' => $this->campaign->channel, 'segment' => $this->campaign->segment,
                'segment_value' => $this->campaign->segment_value, 'subject' => $this->campaign->subject, 'body' => $this->campaign->body,
                'scheduled_at' => $this->campaign->scheduled_at?->format('Y-m-d\TH:i'),
            ]
            : [
                'name' => '', 'channel' => 'whatsapp', 'segment' => 'activos', 'segment_value' => null, 'subject' => '',
                'body' => "Hola {nombre}, ",
                'scheduled_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            ];
        $this->when = $this->campaign?->status === 'scheduled' ? 'schedule' : 'now';
    }

    public function updatedForm($value, $key): void
    {
        if ($key === 'segment') {
            $this->form['segment_value'] = null;
        }
        if (in_array($key, ['segment', 'segment_value', 'channel'])) {
            $this->sampleId = null;
        }
    }

    /** Siguiente cliente del segmento como ejemplo para la vista previa. */
    public function nextSample(CampaignService $service): void
    {
        $q = $service->recipientsQuery($this->form['segment'], $this->form['segment_value'], $this->form['channel']);
        $next = (clone $q)->where('id', '>', (int) $this->sampleId)->orderBy('id')->value('id')
            ?? $q->orderBy('id')->value('id');
        $this->sampleId = $next;
    }

    protected function rules(): array
    {
        return [
            'form.name' => 'required|string|max:150',
            'form.channel' => ['required', Rule::in(array_keys(config('wisp.channels')))],
            'form.segment' => ['required', Rule::in(array_keys(config('wisp.campaign_segments')))],
            'form.segment_value' => [Rule::requiredIf(in_array($this->form['segment'] ?? '', CampaignService::VALUE_SEGMENTS)), 'nullable', 'integer'],
            'form.subject' => [Rule::requiredIf(in_array($this->form['channel'] ?? '', ['email', 'push'])), 'nullable', 'string', 'max:150'],
            'form.body' => 'required|string|max:1600',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['form.name' => 'nombre', 'form.channel' => 'canal', 'form.segment' => 'segmento', 'form.segment_value' => 'valor del segmento',
            'form.subject' => 'asunto', 'form.body' => 'mensaje', 'form.scheduled_at' => 'fecha de envío'];
    }

    protected function persist(string $status): Campaign
    {
        $data = $this->validate()['form'];
        $data['segment_value'] = in_array($data['segment'], CampaignService::VALUE_SEGMENTS) ? $data['segment_value'] : null;
        $data['subject'] = $data['subject'] ?: null;
        $data['status'] = $status;
        $data['scheduled_at'] = $status === 'scheduled' ? Carbon::parse($this->form['scheduled_at']) : null;

        if ($this->campaign) {
            $this->campaign->update($data);
        } else {
            $this->campaign = Campaign::create($data + ['created_by' => auth()->id()]);
        }

        return $this->campaign;
    }

    public function saveDraft()
    {
        $c = $this->persist('draft');
        session()->flash('success', 'Borrador guardado');

        return $this->redirectRoute('communications.campaigns.show', $c, navigate: true);
    }

    public function submit(CampaignService $service)
    {
        if ($this->when === 'schedule') {
            $this->validate(['form.scheduled_at' => 'required|date|after:now'], [], $this->validationAttributes());
            $c = $this->persist('scheduled');
            Audit::log('created', "programó la campaña {$c->name}", $c);
            session()->flash('success', 'Campaña programada para el '.$c->scheduled_at->format('d/m/Y H:i'));

            return $this->redirectRoute('communications.campaigns.show', $c, navigate: true);
        }

        $counts = $service->counts($this->form['segment'], $this->form['segment_value'], $this->form['channel']);
        if ($counts['reachable'] === 0) {
            $this->validate();
            $this->addError('form.segment', 'El segmento no tiene destinatarios con contacto para este canal.');

            return;
        }
        $c = $this->persist('draft');
        $service->start($c);
        Audit::log('created', "envió la campaña {$c->name} a {$c->recipients_count} clientes", $c);
        session()->flash('success', 'Envío iniciado a '.number_format($c->recipients_count).' destinatarios');

        return $this->redirectRoute('communications.campaigns.show', $c, navigate: true);
    }

    public function render(CampaignService $service, MessageService $messages)
    {
        $segment = $this->form['segment'];
        $value = $this->form['segment_value'];
        $counts = $service->counts($segment, $value, $this->form['channel']);

        $sample = $this->sampleId ? Client::with(['plan', 'zone'])->find($this->sampleId) : null;
        $sample ??= (! in_array($segment, CampaignService::VALUE_SEGMENTS) || $value) ? $service->sampleClient($segment, $value, $this->form['channel']) : null;
        $vars = $sample ? $messages->clientVars($sample) : [];
        $vars['link_pago'] = MessageCatalog::sampleLink();
        $vars['descripcion'] ??= '';
        $preview = $messages->render((string) $this->form['body'], $vars);

        $segmentOptions = match ($segment) {
            'zone' => Zone::orderBy('name')->withCount(['clients' => fn ($q) => $q->whereNotIn('service_status', ['cancelado', 'prospecto'])])->get()
                ->mapWithKeys(fn ($z) => [$z->id => "{$z->name} ({$z->clients_count})"]),
            'repeater' => Repeater::withLoad()->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->code} · {$r->name} ({$r->clientLoad()})"]),
            'plan' => Plan::orderBy('price')->withCount(['clients' => fn ($q) => $q->whereNotIn('service_status', ['cancelado', 'prospecto'])])->get()
                ->mapWithKeys(fn ($p) => [$p->id => "{$p->name} · ".money($p->price)." ({$p->clients_count})"]),
            default => collect(),
        };

        return view('livewire.communications.campaigns.edit', [
            'counts' => $counts,
            'sample' => $sample,
            'preview' => $preview,
            'segmentOptions' => $segmentOptions,
        ])->title($this->campaign ? 'Editar campaña' : 'Nueva campaña');
    }
}
