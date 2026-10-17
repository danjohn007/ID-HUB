<?php

namespace App\Livewire\Saas\Sales;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Livewire\Saas\Sales\Concerns\HandlesStages;
use App\Livewire\Saas\Sales\Concerns\SalesContext;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ventas → Prospectos (superadmin): embudo de venta de ID-Hub a WISP. Tablero por etapas con
 * arrastrar y soltar, lista filtrable, captura manual (WhatsApp, llamada…) y métricas del embudo.
 */
#[Title('Prospectos')]
class Leads extends Component
{
    use HandlesStages, PlatformContext, SalesContext, Toasts, WithTable;

    public const VIEWS = ['tablero' => ['Tablero', 'view-columns'], 'lista' => ['Lista', 'list-bullet'], 'metricas' => ['Métricas', 'chart-bar']];

    #[Url(as: 'vista', except: 'tablero')]
    public string $view = 'tablero';

    #[Url(as: 'responsable', except: '')]
    public string $filterAssignee = '';

    #[Url(as: 'origen', except: '')]
    public string $filterSource = '';

    #[Url(as: 'etapa', except: '')]
    public string $filterStage = '';

    #[Url(as: 'vencidos', except: false)]
    public bool $filterOverdue = false;

    #[Url(as: 'periodo', except: '90')]
    public string $period = '90';

    public bool $showForm = false;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = 'tablero';
        }
    }

    /** "Ver todos" de una columna del tablero: lista filtrada por esa etapa. */
    public function showStage(string $stage): void
    {
        $this->view = 'lista';
        $this->filterStage = array_key_exists($stage, DemoRequest::STAGES) ? $stage : '';
        $this->resetPage();
    }

    #[On('meeting-saved')]
    public function refreshAfterMeeting(): void
    {
        $this->refreshBadge();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->form = [
            'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'location' => '', 'clients' => '',
            'current_software' => '', 'source' => 'whatsapp', 'assigned_to' => (string) auth()->id(),
            'next_action' => 'Primer contacto', 'next_action_at' => now()->addHours(2)->format('Y-m-d\TH:i'), 'message' => '',
        ];
        $this->showForm = true;
    }

    public function save()
    {
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => 'nullable|required_without:form.phone|email|max:160',
            'form.phone' => 'nullable|required_without:form.email|string|max:30',
            'form.company' => 'nullable|string|max:160',
            'form.location' => 'nullable|string|max:120',
            'form.clients' => ['nullable', Rule::in(array_keys(DemoRequest::CLIENT_RANGES))],
            'form.current_software' => 'nullable|string|max:80',
            'form.source' => ['required', Rule::in(array_keys(DemoRequest::SOURCES))],
            'form.assigned_to' => 'nullable|exists:users,id',
            'form.next_action' => 'nullable|string|max:190',
            'form.next_action_at' => 'nullable|date',
            'form.message' => 'nullable|string|max:1500',
        ], [
            'form.email.required_without' => 'Captura al menos el correo o el teléfono.',
            'form.phone.required_without' => 'Captura al menos el correo o el teléfono.',
        ], [
            'form.name' => 'nombre', 'form.email' => 'correo', 'form.phone' => 'teléfono', 'form.company' => 'WISP',
            'form.location' => 'ubicación', 'form.clients' => 'clientes', 'form.source' => 'origen', 'form.assigned_to' => 'responsable',
            'form.next_action' => 'siguiente paso', 'form.next_action_at' => 'fecha del siguiente paso', 'form.message' => 'nota',
        ])['form'];

        $lead = DemoRequest::create(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['status' => 'new', 'mail_sent' => false]);
        $lead->log('system', 'Prospecto capturado a mano · origen: '.DemoRequest::SOURCES[$lead->source]);
        $this->showForm = false;
        $this->refreshBadge();
        $this->toast("Prospecto {$lead->name} registrado");

        return $this->redirectRoute('saas.sales.leads.show', $lead, navigate: true);
    }

    protected function filtered(): Builder
    {
        return DemoRequest::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")->orWhere('company', 'like', "%{$this->search}%")
                ->orWhere('phone', 'like', "%{$this->search}%")->orWhere('location', 'like', "%{$this->search}%")))
            ->when($this->filterAssignee === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($this->filterAssignee && $this->filterAssignee !== 'none', fn ($q) => $q->where('assigned_to', $this->filterAssignee))
            ->when($this->filterSource, fn ($q) => $q->where('source', $this->filterSource))
            ->when($this->filterOverdue, fn ($q) => $q->whereNotIn('status', DemoRequest::CLOSED_STAGES)->where('next_action_at', '<', now()));
    }

    protected function withCardData(Builder $q): Builder
    {
        return $q->with(['saasPlan', 'tenant.saasPlan', 'assignee'])
            ->withMin(['meetings as next_meeting_at' => fn ($m) => $m->where('status', 'scheduled')->where('starts_at', '>=', now())], 'starts_at');
    }

    protected function board(): array
    {
        $columns = [];
        foreach (DemoRequest::STAGES as $stage => $label) {
            $q = $this->filtered()->where('status', $stage)
                ->when(in_array($stage, DemoRequest::CLOSED_STAGES, true), fn ($q) => $q->where('stage_changed_at', '>=', now()->subDays(60)));
            $items = $this->withCardData(clone $q)
                ->orderByRaw('next_action_at IS NULL')->orderBy('next_action_at')->latest('id')->limit(40)->get();
            $columns[$stage] = ['label' => $label, 'total' => (clone $q)->count(), 'items' => $items, 'mrr' => $items->sum(fn ($l) => $l->mrr())];
        }

        return $columns;
    }

    protected function metrics(): array
    {
        $from = now()->subDays((int) $this->period)->startOfDay();
        $leads = DemoRequest::with('tenant.saasPlan', 'saasPlan')->where('created_at', '>=', $from)->get();
        $closed = DemoRequest::with('tenant.saasPlan', 'saasPlan')->whereIn('status', DemoRequest::CLOSED_STAGES)->where('stage_changed_at', '>=', $from)->get();
        $won = $closed->where('status', 'won');
        $responses = $leads->filter(fn ($l) => $l->contacted_at)->map(fn ($l) => $l->created_at->diffInMinutes($l->contacted_at));
        $doneMeetings = DemoMeeting::where('status', 'done')->where('starts_at', '>=', $from)->get(['starts_at']);

        $order = array_keys(DemoRequest::STAGES);
        $funnel = [];
        foreach (array_slice($order, 0, 7) as $i => $stage) {
            $n = $leads->filter(fn ($l) => (int) $l->max_stage >= $i)->count();
            $funnel[$stage] = ['label' => DemoRequest::STAGES[$stage], 'n' => $n, 'pct' => $leads->count() ? round($n / $leads->count() * 100) : 0];
        }

        $weeks = collect(range(7, 0))->map(fn ($w) => now()->startOfWeek()->subWeeks($w));
        $newLeads = DemoRequest::where('created_at', '>=', $weeks->first())->get(['created_at']);

        return [
            'kpi' => [
                'leads' => $leads->count(),
                'demos' => $doneMeetings->count(),
                'conversion' => $closed->count() ? round($won->count() / $closed->count() * 100) : null,
                'won' => $won->count(),
                'mrr' => $won->sum(fn ($l) => $l->mrr()),
                'response' => $responses->isEmpty() ? null : (int) round($responses->avg()),
            ],
            'funnel' => $funnel,
            'lost' => $closed->where('status', 'lost')->countBy(fn ($l) => $l->lost_reason ?: 'other')->sortDesc(),
            'lostTotal' => $closed->where('status', 'lost')->count(),
            'sources' => $leads->groupBy('source')->map(fn ($g) => ['n' => $g->count(), 'won' => $g->where('status', 'won')->count()])->sortByDesc('n'),
            'chart' => [
                'tooltip' => ['trigger' => 'axis'],
                'legend' => ['top' => 0],
                'grid' => ['left' => 30, 'right' => 10, 'top' => 36, 'bottom' => 24],
                'xAxis' => ['type' => 'category', 'data' => $weeks->map(fn ($w) => $w->translatedFormat('d M'))->all()],
                'yAxis' => ['type' => 'value', 'minInterval' => 1],
                'series' => [
                    ['name' => 'Prospectos nuevos', 'type' => 'bar', 'data' => $weeks->map(fn ($w) => $newLeads->filter(fn ($l) => $l->created_at->between($w, $w->copy()->endOfWeek()))->count())->all()],
                    ['name' => 'Demos realizadas', 'type' => 'bar', 'data' => $weeks->map(fn ($w) => DemoMeeting::where('status', 'done')->whereBetween('starts_at', [$w, $w->copy()->endOfWeek()])->count())->all()],
                ],
            ],
        ];
    }

    public function render()
    {
        $data = ['users' => $this->salesUsers()];
        if ($this->view === 'tablero') {
            $data['columns'] = $this->board();
        } elseif ($this->view === 'lista') {
            $data['leads'] = $this->withCardData($this->filtered()->when($this->filterStage, fn ($q) => $q->where('status', $this->filterStage)))
                ->latest()->latest('id')->paginate($this->perPage);
        } else {
            $data['m'] = $this->metrics();
        }
        $data['summary'] = [
            'open' => DemoRequest::whereNotIn('status', DemoRequest::CLOSED_STAGES)->count(),
            'new' => DemoRequest::where('status', 'new')->count(),
            'overdue' => DemoRequest::whereNotIn('status', DemoRequest::CLOSED_STAGES)->where('next_action_at', '<', now())->count(),
            'today' => DemoMeeting::where('status', 'scheduled')->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])->count(),
        ];

        return view('livewire.saas.sales.leads', $data);
    }
}
