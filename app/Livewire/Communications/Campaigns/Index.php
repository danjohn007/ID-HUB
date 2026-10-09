<?php

namespace App\Livewire\Communications\Campaigns;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Campaign;
use App\Models\Message;
use App\Services\Messaging\CampaignService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Notificaciones masivas (sección 36): listado de campañas.
 */
#[Title('Campañas')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'canal', except: '')]
    public string $filterChannel = '';

    protected array $sortable = ['name', 'created_at', 'scheduled_at', 'sent_at', 'recipients_count'];

    public function duplicate(int $id)
    {
        $c = Campaign::findOrFail($id);
        $copy = Campaign::create([
            'name' => $c->name.' (copia)', 'channel' => $c->channel, 'segment' => $c->segment, 'segment_value' => $c->segment_value,
            'subject' => $c->subject, 'body' => $c->body, 'status' => 'draft', 'created_by' => auth()->id(),
        ]);

        return $this->redirectRoute('communications.campaigns.edit', $copy, navigate: true);
    }

    public function delete(int $id): void
    {
        $c = Campaign::findOrFail($id);
        if (! in_array($c->status, ['draft', 'cancelled'])) {
            $this->toast('Solo se pueden eliminar borradores o campañas canceladas', 'warning');

            return;
        }
        $c->delete();
        $this->toast('Campaña eliminada');
    }

    public function render(CampaignService $service)
    {
        $campaigns = $this->applySort(
            Campaign::query()->with('createdBy:id,name')
                ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
                ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
                ->when($this->filterChannel, fn ($q) => $q->where('channel', $this->filterChannel)),
            'created_at', 'desc'
        )->paginate($this->perPage);

        $month = Campaign::query()->where('sent_at', '>=', now()->startOfMonth());

        return view('livewire.communications.campaigns.index', [
            'campaigns' => $campaigns,
            'service' => $service,
            'counts' => Campaign::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'kpi' => [
                'sent_month' => (clone $month)->count(),
                'recipients_month' => (int) (clone $month)->sum('recipients_count'),
                'delivered_month' => (int) (clone $month)->sum('sent_count'),
                'failed_month' => (int) (clone $month)->sum('failed_count'),
                'scheduled' => Campaign::query()->where('status', 'scheduled')->count(),
                'next' => Campaign::query()->where('status', 'scheduled')->orderBy('scheduled_at')->first(),
                'messages_month' => Message::query()->whereNotNull('campaign_id')->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }
}
