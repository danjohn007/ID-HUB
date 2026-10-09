<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\CashCut;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Zone;
use App\Support\Audit;
use App\Support\Catalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Control de cobradores (sección 26): desempeño por cobrador, visitas, mapa del día
 * y asignación masiva de clientes.
 */
#[Title('Cobradores')]
class Collections extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'resultado', except: '')]
    public string $filterResult = '';

    #[Url(as: 'cobrador', except: '')]
    public string $filterCollector = '';

    #[Url(as: 'desde', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'hasta', except: '')]
    public string $filterTo = '';

    public string $mapDate = '';

    // Asignación masiva
    public bool $showAssign = false;

    public array $assign = ['zone_id' => '', 'only_debt' => true, 'only_unassigned' => false, 'status' => '', 'from_collector' => '', 'collector_id' => ''];

    protected array $sortable = ['visited_at', 'amount_due'];

    public function mount(): void
    {
        $this->filterFrom = $this->filterFrom ?: now()->subDays(6)->toDateString();
        $this->filterTo = $this->filterTo ?: now()->toDateString();
        $this->mapDate = now()->toDateString();
    }

    public function updatedMapDate(): void
    {
        $this->dispatch('map-data-collections-map', data: ['markers' => $this->mapMarkers()]);
    }

    public function openAssign(): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->showAssign = true;
    }

    protected function assignQuery()
    {
        $a = $this->assign;

        return Client::query()->whereIn('service_status', Client::BILLABLE)
            ->when($a['zone_id'], fn ($q) => $q->where('zone_id', $a['zone_id']))
            ->when($a['only_debt'], fn ($q) => $q->where('balance', '>', 0))
            ->when($a['only_unassigned'], fn ($q) => $q->whereNull('collector_id'))
            ->when($a['status'], fn ($q) => $q->where('service_status', $a['status']))
            ->when($a['from_collector'], fn ($q) => $q->where('collector_id', $a['from_collector']));
    }

    public function applyAssign(): void
    {
        $this->authorize('billing.manage');
        $this->validate(['assign.collector_id' => 'required'], [], ['assign.collector_id' => 'cobrador']);
        $collectorId = $this->assign['collector_id'] === 'none' ? null
            : User::forTenant()->role('cobrador')->whereKey($this->assign['collector_id'])->value('id');
        if ($this->assign['collector_id'] !== 'none' && ! $collectorId) {
            $this->addError('assign.collector_id', 'Cobrador no válido');

            return;
        }
        $n = $this->assignQuery()->update(['collector_id' => $collectorId, 'updated_at' => now()]);
        $name = $collectorId ? User::find($collectorId)->name : 'ningún cobrador';
        Audit::log('assign_collector', "asignó {$n} clientes a {$name}");
        $this->showAssign = false;
        $this->toast("{$n} clientes asignados a {$name}");
    }

    protected function visitsQuery()
    {
        return CollectionVisit::query()
            ->with(['client:id,code,first_name,last_name,address,balance,service_status', 'collector:id,name', 'payment:id,folio,amount'])
            ->when($this->filterResult, fn ($q) => $q->where('result', $this->filterResult))
            ->when($this->filterCollector, fn ($q) => $q->where('collector_id', $this->filterCollector))
            ->when($this->filterFrom, fn ($q) => $q->where('visited_at', '>=', $this->filterFrom.' 00:00:00'))
            ->when($this->filterTo, fn ($q) => $q->where('visited_at', '<=', $this->filterTo.' 23:59:59'))
            ->when($this->search, fn ($q) => $q->whereHas('client', fn ($c) => $c->search($this->search)));
    }

    /** KPIs por cobrador. */
    protected function collectorStats($collectors): array
    {
        $ids = $collectors->pluck('id');
        $today = now()->startOfDay();
        $week = now()->startOfWeek();

        $paid = Payment::query()->revenue()->whereIn('collector_id', $ids)->where('paid_at', '>=', $week)
            ->selectRaw('collector_id, SUM(CASE WHEN paid_at >= ? THEN amount ELSE 0 END) today, SUM(amount) week, COUNT(*) n_week', [$today])
            ->groupBy('collector_id')->toBase()->get()->keyBy('collector_id');
        $visits = CollectionVisit::query()->whereIn('collector_id', $ids)->where('visited_at', '>=', $week)
            ->selectRaw("collector_id, SUM(CASE WHEN visited_at >= ? THEN 1 ELSE 0 END) today, COUNT(*) week, SUM(CASE WHEN result = 'pago' THEN 1 ELSE 0 END) paid, SUM(CASE WHEN result = 'promesa' THEN 1 ELSE 0 END) promises", [$today])
            ->groupBy('collector_id')->toBase()->get()->keyBy('collector_id');
        $clients = Client::query()->whereIn('collector_id', $ids)->whereIn('service_status', Client::BILLABLE)
            ->selectRaw('collector_id, COUNT(*) total, SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) debtors, SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) debt')
            ->groupBy('collector_id')->toBase()->get()->keyBy('collector_id');
        $cuts = CashCut::query()->whereIn('collector_id', $ids)->where('status', 'open')
            ->selectRaw('collector_id, SUM(expected_amount) cash')->groupBy('collector_id')->toBase()->get()->keyBy('collector_id');

        return $collectors->map(function ($u) use ($paid, $visits, $clients, $cuts) {
            $v = $visits[$u->id] ?? null;
            $p = $paid[$u->id] ?? null;
            // Efectividad: visitas con pago (o pagos registrados) / gestiones de la semana
            $gestiones = max((int) ($v->week ?? 0), (int) ($p->n_week ?? 0));
            $successes = max((int) ($v->paid ?? 0), (int) ($p->n_week ?? 0));

            return [
                'user' => $u,
                'today' => (float) ($p->today ?? 0),
                'week' => (float) ($p->week ?? 0),
                'visits_today' => (int) ($v->today ?? 0),
                'visits_week' => (int) ($v->week ?? 0),
                'promises' => (int) ($v->promises ?? 0),
                'effectiveness' => $gestiones ? round(min(100, $successes / $gestiones * 100), 1) : 0,
                'clients' => (int) ($clients[$u->id]->total ?? 0),
                'debtors' => (int) ($clients[$u->id]->debtors ?? 0),
                'debt' => (float) ($clients[$u->id]->debt ?? 0),
                'cash' => (float) ($cuts[$u->id]->cash ?? 0),
            ];
        })->all();
    }

    protected function mapMarkers(): array
    {
        $date = $this->mapDate ? Carbon::parse($this->mapDate) : now();
        $colors = ['pago' => '#22A06B', 'no_localizado' => '#94A3B8', 'promesa' => '#1A7FF5', 'rechazo' => '#E5484D', 'cerrado' => '#F7881F'];

        $visits = CollectionVisit::query()->with(['client:id,code,first_name,last_name,lat,lng', 'collector:id,name'])
            ->whereBetween('visited_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->get()
            ->map(function ($v) use ($colors) {
                $lat = $v->lat ?? $v->client?->lat;
                $lng = $v->lng ?? $v->client?->lng;
                if (! $lat) {
                    return null;
                }

                return [
                    'lat' => (float) $lat, 'lng' => (float) $lng, 'type' => 'client', 'layer' => 'visits', 'size' => 24,
                    'color' => $colors[$v->result] ?? '#94A3B8',
                    'popup' => '<b>'.e($v->client?->full_name).'</b><br>'.Catalog::label('visit_results', $v->result).' · '.$v->visited_at->format('H:i')
                        .'<br>Cobrador: '.e($v->collector?->name).($v->notes ? '<br>'.e($v->notes) : ''),
                ];
            })->filter()->values();

        $staff = User::forTenant()->active()->role('cobrador')->whereNotNull('last_lat')->get()->map(fn ($u) => [
            'lat' => (float) $u->last_lat, 'lng' => (float) $u->last_lng, 'type' => 'collector', 'layer' => 'collectors',
            'color' => '#6048D0', 'size' => 30,
            'popup' => '<b>'.e($u->name).'</b><br>Cobrador · ubicación '.($u->last_location_at?->diffForHumans() ?? 'sin fecha'),
        ]);

        return $visits->concat($staff)->all();
    }

    public function render()
    {
        $collectors = User::forTenant()->role('cobrador')->orderByDesc('active')->orderBy('name')->get();
        $today = now()->startOfDay();

        return view('livewire.billing.collections', [
            'stats' => $this->collectorStats($collectors),
            'totals' => [
                'today' => (float) Payment::query()->revenue()->whereNotNull('collector_id')->where('paid_at', '>=', $today)->sum('amount'),
                'week' => (float) Payment::query()->revenue()->whereNotNull('collector_id')->where('paid_at', '>=', now()->startOfWeek())->sum('amount'),
                'visits_today' => CollectionVisit::query()->where('visited_at', '>=', $today)->count(),
                'unassigned' => Client::query()->whereIn('service_status', Client::BILLABLE)->whereNull('collector_id')->where('balance', '>', 0)->count(),
            ],
            'visits' => $this->applySort($this->visitsQuery(), 'visited_at', 'desc')->paginate($this->perPage),
            'resultCounts' => $this->visitsQuery()->reorder()->setEagerLoads([])->selectRaw('result, COUNT(*) n')->groupBy('result')->toBase()->pluck('n', 'result'),
            'markers' => $this->mapMarkers(),
            'collectorOptions' => $collectors->where('active', true)->pluck('name', 'id'),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'assignCount' => $this->showAssign ? $this->assignQuery()->count() : 0,
        ]);
    }
}
