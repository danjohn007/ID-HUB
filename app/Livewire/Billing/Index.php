<?php

namespace App\Livewire\Billing;

use App\Livewire\Billing\Concerns\RegistersPayments;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\AutomationRule;
use App\Models\Charge;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Zone;
use App\Services\Billing\CollectionAutomation;
use App\Services\BillingService;
use App\Services\Messaging\MessageService;
use App\Services\MetricsService;
use App\Services\Payments\PaymentGatewayService;
use App\Services\ReportExporter;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cobranza (secciones 6, 8 y 53): cartera vencida, cargos pendientes,
 * política de cobranza automática y promesas de pago.
 */
#[Title('Cobranza')]
class Index extends Component
{
    use RegistersPayments, Toasts, WithTable;

    #[Url]
    public string $tab = 'cartera';

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'riesgo', except: '')]
    public string $filterRisk = '';

    #[Url(as: 'cobrador', except: '')]
    public string $filterCollector = '';

    #[Url(as: 'atraso', except: '')]
    public string $filterAging = '';

    #[Url(as: 'vence', except: '')]
    public string $filterDue = '';

    #[Url(as: 'tipo', except: '')]
    public string $filterType = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    #[Url(as: 'promesa', except: '')]
    public string $filterPromise = 'vigente';

    protected array $sortable = ['overdue_days', 'overdue_amount', 'balance', 'last_payment_at', 'due_date', 'total', 'promise_date', 'visited_at'];

    public const AGING = ['1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, 100000]];

    public function updatedTab(): void
    {
        $this->reset('sortBy', 'search', 'filterZone', 'filterStatus', 'filterRisk', 'filterCollector', 'filterAging', 'filterDue', 'filterType', 'filterFrom', 'filterTo');
        $this->sortDir = 'desc';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filterZone', 'filterStatus', 'filterRisk', 'filterCollector', 'filterAging', 'filterDue', 'filterType', 'filterFrom', 'filterTo');
        $this->resetPage();
    }

    #[On('payment-registered')]
    public function refreshAfterPayment(): void
    {
        // re-render
    }

    // ------------------------------------------------------------ Acciones

    public function sendLink(int $clientId, BillingService $billing, MessageService $messages): void
    {
        $this->authorize('billing.manage');
        $client = Client::findOrFail($clientId);
        $link = $billing->paymentLinkFor($client);
        if (! $link) {
            $this->toast('El cliente no tiene saldo pendiente', 'warning');

            return;
        }
        $sent = $messages->notify($client, 'payment_link', ['link_pago' => $link->url(), 'monto' => money($link->amount)]);
        $this->toast($sent->isNotEmpty() ? "Enlace \"Pagar ahora\" enviado a {$client->full_name}" : 'El cliente no tiene teléfono/correo registrado', $sent->isNotEmpty() ? 'success' : 'warning');
    }

    public function sendReminder(int $clientId, MessageService $messages): void
    {
        $this->authorize('billing.manage');
        $client = Client::findOrFail($clientId);
        $sent = $messages->notify($client, 'pending');
        $this->toast($sent->isNotEmpty() ? "Recordatorio enviado a {$client->full_name}" : 'El cliente no tiene teléfono/correo registrado', $sent->isNotEmpty() ? 'success' : 'warning');
    }

    public function runGenerate(CollectionAutomation $automation): void
    {
        $this->authorize('billing.manage');
        $stats = $automation->generateCharges(tenant());
        $this->toast($stats['charges'] > 0 ? "Se generaron {$stats['charges']} cargos del periodo" : 'No hay cargos por generar hoy (ya existen o no es día de corte)', $stats['charges'] > 0 ? 'success' : 'info');
    }

    public function runReminders(CollectionAutomation $automation): void
    {
        $this->authorize('billing.manage');
        $s = $automation->sendReminders(tenant(), force: true);
        $this->toast("Recordatorios: {$s['reminders']} · Avisos de vencimiento: {$s['due']} · Avisos de suspensión: {$s['warnings']} · Nuevos morosos: {$s['morosos']}", 'info');
    }

    public function export(string $format = 'xlsx')
    {
        if ($this->tab === 'cargos') {
            $rows = $this->chargesQuery()->orderBy('due_date')->get();

            return app(ReportExporter::class)->download('cargos-pendientes', $format, [
                'Folio' => 'folio', 'Cliente' => 'client.full_name', 'Código' => 'client.code', 'Zona' => 'client.zone.name',
                'Concepto' => 'description', 'Tipo' => fn ($c) => Catalog::label('charge_types', $c->type),
                'Vence' => fn ($c) => $c->due_date?->format('d/m/Y'), 'Total' => fn ($c) => (float) $c->total,
                'Pagado' => fn ($c) => (float) $c->paid_amount, 'Saldo' => fn ($c) => $c->balance(),
                'Estado' => fn ($c) => Catalog::label('charge_status', $c->status),
            ], $rows, 'Cargos pendientes');
        }

        $rows = $this->portfolioQuery()->orderByDesc('overdue_days')->get();

        return app(ReportExporter::class)->download('cartera-vencida', $format, [
            'Código' => 'code', 'Cliente' => 'full_name', 'Teléfono' => 'phone', 'Zona' => 'zone.name', 'Cobrador' => 'collector.name',
            'Estado' => fn ($c) => Catalog::label('client_status', $c->service_status),
            'Saldo vencido' => fn ($c) => (float) $c->overdue_amount, 'Saldo total' => fn ($c) => (float) $c->balance,
            'Días de atraso' => fn ($c) => (int) $c->overdue_days, 'Riesgo' => fn ($c) => Catalog::label('risk_levels', $c->risk_level),
            'Último pago' => fn ($c) => $c->last_payment_at ? \Carbon\Carbon::parse($c->last_payment_at)->format('d/m/Y') : '',
        ], $rows, 'Cartera vencida', ['Saldo vencido' => money($rows->sum('overdue_amount')), 'Clientes' => $rows->count()]);
    }

    // ------------------------------------------------------------ Consultas

    protected function portfolioQuery()
    {
        $today = now()->toDateString();
        $overdue = DB::table('charges')->where('tenant_id', tenant()->id)->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $today)
            ->selectRaw('client_id, SUM(total - paid_amount) overdue_amount, MIN(due_date) oldest_due, COUNT(*) overdue_charges')
            ->groupBy('client_id');

        return Client::query()
            ->joinSub($overdue, 'od', 'od.client_id', '=', 'clients.id')
            ->select('clients.*', 'od.overdue_amount', 'od.oldest_due', 'od.overdue_charges')
            ->selectRaw('DATEDIFF(?, od.oldest_due) AS overdue_days', [$today])
            ->addSelect(['last_payment_at' => Payment::query()->select('paid_at')->whereColumn('payments.client_id', 'clients.id')
                ->where('payments.kind', 'payment')->where('payments.status', 'completed')->orderByDesc('paid_at')->limit(1)])
            ->with(['zone:id,name', 'collector:id,name', 'plan:id,name'])
            ->search($this->search)
            ->when($this->filterZone, fn ($q) => $q->where('clients.zone_id', $this->filterZone))
            ->when($this->filterStatus, fn ($q) => $q->where('clients.service_status', $this->filterStatus))
            ->when($this->filterRisk, fn ($q) => $q->where('clients.risk_level', $this->filterRisk))
            ->when($this->filterCollector === 'none', fn ($q) => $q->whereNull('clients.collector_id'))
            ->when($this->filterCollector && $this->filterCollector !== 'none', fn ($q) => $q->where('clients.collector_id', $this->filterCollector))
            ->when(isset(self::AGING[$this->filterAging]), fn ($q) => $q->whereRaw('DATEDIFF(?, od.oldest_due) BETWEEN ? AND ?', [$today, ...self::AGING[$this->filterAging]]));
    }

    protected function chargesQuery()
    {
        $today = now();

        return Charge::query()->open()
            ->with(['client:id,code,first_name,last_name,zone_id,service_status,phone', 'client.zone:id,name'])
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('folio', 'like', '%'.$this->search.'%')
                ->orWhereHas('client', fn ($c) => $c->search($this->search))))
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterZone, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('zone_id', $this->filterZone)))
            ->when($this->filterDue === 'vencidos', fn ($q) => $q->where('due_date', '<', $today->toDateString()))
            ->when($this->filterDue === 'hoy', fn ($q) => $q->whereDate('due_date', $today->toDateString()))
            ->when($this->filterDue === 'semana', fn ($q) => $q->whereBetween('due_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()]))
            ->when($this->filterDue === 'futuros', fn ($q) => $q->where('due_date', '>', $today->toDateString()))
            ->when($this->filterFrom, fn ($q) => $q->where('due_date', '>=', $this->filterFrom))
            ->when($this->filterTo, fn ($q) => $q->where('due_date', '<=', $this->filterTo));
    }

    protected function promisesQuery()
    {
        $today = now()->toDateString();
        $fulfilled = fn ($q) => $q->from('payments')->whereColumn('payments.client_id', 'collection_visits.client_id')
            ->whereColumn('payments.paid_at', '>=', 'collection_visits.visited_at')
            ->where('payments.kind', 'payment')->where('payments.status', 'completed');

        return CollectionVisit::query()->where('result', 'promesa')->whereNotNull('promise_date')
            ->with(['client:id,code,first_name,last_name,balance,service_status,phone,zone_id', 'client.zone:id,name', 'collector:id,name'])
            ->addSelect(['fulfilled_at' => Payment::query()->select('paid_at')->whereColumn('payments.client_id', 'collection_visits.client_id')
                ->whereColumn('payments.paid_at', '>=', 'collection_visits.visited_at')
                ->where('payments.kind', 'payment')->where('payments.status', 'completed')->orderBy('paid_at')->limit(1)])
            ->when($this->search, fn ($q) => $q->whereHas('client', fn ($c) => $c->search($this->search)))
            ->when($this->filterCollector, fn ($q) => $q->where('collector_id', $this->filterCollector))
            ->when($this->filterPromise === 'vigente', fn ($q) => $q->whereNotExists($fulfilled)->where('promise_date', '>=', $today))
            ->when($this->filterPromise === 'incumplida', fn ($q) => $q->whereNotExists($fulfilled)->where('promise_date', '<', $today))
            ->when($this->filterPromise === 'cumplida', fn ($q) => $q->whereExists($fulfilled));
    }

    protected function kpis(MetricsService $metrics): array
    {
        $del = $metrics->delinquency();
        $today = now()->toDateString();
        $receivable = (float) DB::table('charges')->where('tenant_id', tenant()->id)->whereIn('status', ['pending', 'partial'])->sum(DB::raw('total - paid_amount'));
        $promises = CollectionVisit::query()->where('result', 'promesa')->where('promise_date', '>=', $today)
            ->whereNotExists(fn ($q) => $q->from('payments')->whereColumn('payments.client_id', 'collection_visits.client_id')
                ->whereColumn('payments.paid_at', '>=', 'collection_visits.visited_at')->where('payments.kind', 'payment')->where('payments.status', 'completed'))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(promise_amount), 0) amount')->first();

        return [
            'receivable' => $receivable,
            'overdue' => $del['amount'],
            'collected' => $metrics->collected(),
            'collected_prev' => $metrics->collected(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()),
            'recovery' => $metrics->recoveryRate(),
            'morosos' => $del['morosos'],
            'suspendidos' => $del['suspendidos'],
            'rate' => $del['rate'],
            'promises' => (int) $promises->n,
            'promises_amount' => (float) $promises->amount,
        ];
    }

    public function render(MetricsService $metrics, CollectionAutomation $automation, PaymentGatewayService $gateways)
    {
        $allowed = ['cartera' => ['overdue_days', 'overdue_amount', 'balance', 'last_payment_at'], 'cargos' => ['due_date', 'total'], 'promesas' => ['promise_date', 'visited_at']];
        if ($this->sortBy && ! in_array($this->sortBy, $allowed[$this->tab] ?? [], true)) {
            $this->sortBy = '';
        }
        $data = [
            'kpi' => $this->kpis($metrics),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'collectors' => User::forTenant()->active()->role('cobrador')->orderBy('name')->pluck('name', 'id'),
        ];

        if ($this->tab === 'cartera') {
            $q = $this->portfolioQuery();
            $data['portfolio'] = $this->applySort(clone $q, 'overdue_days', 'desc')->paginate($this->perPage);
            $data['portfolioTotals'] = DB::query()->fromSub((clone $q)->reorder(), 'p')
                ->selectRaw('COUNT(*) n, COALESCE(SUM(overdue_amount), 0) amount')->first();
            $data['agingBuckets'] = DB::query()->fromSub($this->portfolioQueryUnfiltered(), 'p')
                ->selectRaw("CASE WHEN overdue_days <= 30 THEN '1-30' WHEN overdue_days <= 60 THEN '31-60' WHEN overdue_days <= 90 THEN '61-90' ELSE '90+' END bucket, COUNT(*) n, SUM(overdue_amount) amount")
                ->groupBy('bucket')->get()->keyBy('bucket');
        } elseif ($this->tab === 'cargos') {
            $q = $this->chargesQuery();
            $data['charges'] = $this->applySort(clone $q, 'due_date', 'asc')->paginate($this->perPage);
            $data['chargesTotal'] = (float) (clone $q)->sum(DB::raw('total - paid_amount'));
            $data['chargesCount'] = (clone $q)->count();
        } elseif ($this->tab === 'promesas') {
            $data['promises'] = $this->applySort($this->promisesQuery(), 'promise_date', 'asc')->paginate($this->perPage);
        } else {
            $tenant = tenant();
            $data['policy'] = $tenant->settingsFor('billing');
            $data['lastRuns'] = $automation->lastRuns($tenant);
            $data['preview'] = $automation->enforceCutoffs($tenant, force: true, dryRun: true);
            $data['reminderPreview'] = $automation->sendReminders($tenant, force: true, dryRun: true);
            $data['rules'] = AutomationRule::query()->whereIn('event', ['payment_overdue', 'payment_due_soon', 'payment_received'])->orderBy('id')->get();
            $data['gateways'] = $gateways->enabledFor($tenant);
            $data['gatewayService'] = $gateways;
        }

        return view('livewire.billing.index', $data);
    }

    /** Cartera vencida sin filtros (para la antigüedad de saldos). */
    protected function portfolioQueryUnfiltered()
    {
        $today = now()->toDateString();

        return DB::table('charges')->where('tenant_id', tenant()->id)->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', $today)
            ->whereExists(fn ($q) => $q->from('clients')->whereColumn('clients.id', 'charges.client_id')->whereNull('clients.deleted_at'))
            ->selectRaw('client_id, SUM(total - paid_amount) overdue_amount, DATEDIFF(?, MIN(due_date)) overdue_days', [$today])
            ->groupBy('client_id');
    }
}
