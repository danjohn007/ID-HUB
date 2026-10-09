<?php

namespace App\Livewire\Communications\Campaigns;

use App\Livewire\Concerns\Toasts;
use App\Models\Campaign;
use App\Models\Message;
use App\Services\Messaging\CampaignService;
use App\Support\Audit;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Progreso y resultados de una campaña: enviados, fallidos, en cola y detalle por destinatario.
 * Mientras está "enviando", la vista procesa lotes con sondeo (además del scheduler).
 */
class Show extends Component
{
    use Toasts, WithPagination;

    public Campaign $campaign;

    public string $filterStatus = '';

    public string $search = '';

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
        app(CampaignService::class)->refreshCounts($campaign);
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Sondeo: procesa el siguiente lote y refresca contadores. */
    public function tick(CampaignService $service): void
    {
        $this->campaign->refresh();
        if ($this->campaign->status === 'sending') {
            $service->process($this->campaign, 150);
        } else {
            $service->refreshCounts($this->campaign);
        }
        $this->campaign->refresh();
    }

    public function sendNow(CampaignService $service): void
    {
        if (! in_array($this->campaign->status, ['draft', 'scheduled'])) {
            return;
        }
        if ($service->counts($this->campaign->segment, $this->campaign->segment_value, $this->campaign->channel)['reachable'] === 0) {
            $this->toast('El segmento no tiene destinatarios para este canal', 'error');

            return;
        }
        $this->campaign->update(['scheduled_at' => null]);
        $service->start($this->campaign);
        Audit::log('updated', "envió la campaña {$this->campaign->name}", $this->campaign);
        $this->campaign->refresh();
        $this->toast('Envío iniciado');
    }

    public function cancel(): void
    {
        if (! in_array($this->campaign->status, ['draft', 'scheduled', 'sending'])) {
            return;
        }
        $this->campaign->update(['status' => 'cancelled']);
        Audit::log('updated', "canceló la campaña {$this->campaign->name}", $this->campaign);
        $this->toast('Campaña cancelada', 'warning');
    }

    public function retryFailed(CampaignService $service): void
    {
        $n = $service->retryFailed($this->campaign);
        $this->campaign->refresh();
        $this->toast($n ? "$n mensajes reenviados" : 'No hay mensajes fallidos', $n ? 'success' : 'info');
    }

    public function duplicate()
    {
        $c = $this->campaign;
        $copy = Campaign::create([
            'name' => $c->name.' (copia)', 'channel' => $c->channel, 'segment' => $c->segment, 'segment_value' => $c->segment_value,
            'subject' => $c->subject, 'body' => $c->body, 'status' => 'draft', 'created_by' => auth()->id(),
        ]);

        return $this->redirectRoute('communications.campaigns.edit', $copy, navigate: true);
    }

    public function render(CampaignService $service)
    {
        $stats = Message::query()->where('campaign_id', $this->campaign->id)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $term = trim($this->search);

        $messages = Message::query()->where('campaign_id', $this->campaign->id)->with('client:id,code,first_name,last_name')
            ->when($this->filterStatus, fn ($q) => $this->filterStatus === 'sent' ? $q->whereIn('status', CampaignService::SENT_STATUSES) : $q->where('status', $this->filterStatus))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('to', 'like', "%$term%")
                ->orWhereIn('client_id', \App\Models\Client::query()->select('id')->search($term))))
            ->latest('id')->paginate(25);

        $created = (int) $stats->sum();
        $c = $this->campaign;

        return view('livewire.communications.campaigns.show', [
            'stats' => $stats,
            'messages' => $messages,
            'segmentLabel' => $service->segmentLabel($c),
            'queued' => (int) ($stats['queued'] ?? 0),
            'pending' => $service->pending($c),
            'created' => $created,
            'hasMessages' => $created > 0,
            'progress' => $c->recipients_count ? min(100, round(($c->sent_count + $c->failed_count) / $c->recipients_count * 100, 1)) : 0,
        ])->title($c->name);
    }
}
