<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\CashCut;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashCutService;
use App\Services\ReportExporter;
use App\Support\Catalog;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Control de efectivo (sección 27): cortes de caja por cobrador, cobros registrados
 * vs. efectivo entregado y conciliación del supervisor.
 */
#[Title('Cortes de caja')]
class Cash extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'cobrador', except: '')]
    public string $filterCollector = '';

    #[Url(as: 'corte', except: null)]
    public ?int $viewId = null;

    public bool $showDetail = false;

    public bool $showDeliver = false;

    public bool $showReconcile = false;

    public bool $showCreate = false;

    public ?int $actionId = null;

    public string $deliveredAmount = '';

    public string $notes = '';

    public string $newCollector = '';

    protected array $sortable = ['opened_at', 'expected_amount', 'difference'];

    public function mount(): void
    {
        if ($this->viewId) {
            $this->showDetail = true;
        }
    }

    public function updatedShowDetail(bool $value): void
    {
        if (! $value) {
            $this->viewId = null;
        }
    }

    public function view(int $id): void
    {
        $this->viewId = $id;
        $this->showDetail = true;
    }

    protected function cut(int $id): CashCut
    {
        return CashCut::findOrFail($id);
    }

    public function closeCut(int $id, CashCutService $service): void
    {
        $this->authorize('cash.reconcile');
        $cut = $service->close($this->cut($id));
        $this->toast("Corte {$cut->folio} cerrado: {$cut->payments_count} cobros, esperado ".money($cut->expected_amount));
    }

    public function openDeliver(int $id): void
    {
        $this->authorize('cash.reconcile');
        $cut = $this->cut($id);
        $this->actionId = $id;
        $this->deliveredAmount = (string) ($cut->delivered_amount ?? $cut->expected_amount);
        $this->notes = '';
        $this->resetValidation();
        $this->showDeliver = true;
    }

    public function deliver(CashCutService $service): void
    {
        $this->authorize('cash.reconcile');
        $this->validate(['deliveredAmount' => 'required|numeric|min:0|max:10000000', 'notes' => 'nullable|string|max:300'], [], ['deliveredAmount' => 'efectivo entregado']);
        try {
            $cut = $service->deliver($this->cut($this->actionId), (float) $this->deliveredAmount, $this->notes);
        } catch (\RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }
        $this->showDeliver = false;
        $diff = (float) $cut->difference;
        $this->toast("Entrega registrada en {$cut->folio}".(abs($diff) > 0.009 ? '. Diferencia: '.money($diff) : '. Sin diferencias'), abs($diff) > 0.009 ? 'warning' : 'success');
    }

    public function openReconcile(int $id): void
    {
        $this->authorize('cash.reconcile');
        $this->actionId = $id;
        $this->notes = '';
        $this->showReconcile = true;
    }

    public function reconcile(CashCutService $service): void
    {
        $this->authorize('cash.reconcile');
        $this->validate(['notes' => (abs((float) $this->cut($this->actionId)->difference) > 0.009 ? 'required' : 'nullable').'|string|max:300'], [], ['notes' => 'nota de conciliación']);
        try {
            $cut = $service->reconcile($this->cut($this->actionId), auth()->user(), $this->notes);
        } catch (\RuntimeException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }
        $this->showReconcile = false;
        $this->toast("Corte {$cut->folio} conciliado");
    }

    public function openCreate(): void
    {
        $this->authorize('cash.reconcile');
        $this->newCollector = '';
        $this->resetValidation();
        $this->showCreate = true;
    }

    public function createCut(CashCutService $service): void
    {
        $this->authorize('cash.reconcile');
        $this->validate(['newCollector' => 'required'], [], ['newCollector' => 'cobrador']);
        $id = User::forTenant()->role('cobrador')->whereKey($this->newCollector)->value('id');
        if (! $id) {
            $this->addError('newCollector', 'Cobrador no válido');

            return;
        }
        $hadOpen = (bool) $service->openCutFor($id, false);
        $cut = $service->createFor($id);
        $this->showCreate = false;
        $this->toast(($hadOpen ? "El cobrador ya tenía el corte abierto {$cut->folio}; se actualizó" : "Corte {$cut->folio} creado").": {$cut->payments_count} cobros, ".money($cut->expected_amount));
        $this->view($cut->id);
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->orderByDesc('opened_at')->get();

        return app(ReportExporter::class)->download('cortes-de-caja', $format, [
            'Folio' => 'folio', 'Cobrador' => 'collector.name', 'Apertura' => fn ($c) => $c->opened_at?->format('d/m/Y H:i'),
            'Cierre' => fn ($c) => $c->closed_at?->format('d/m/Y H:i'), 'Cobros' => 'payments_count',
            'Esperado' => fn ($c) => (float) $c->expected_amount, 'Entregado' => fn ($c) => $c->delivered_amount !== null ? (float) $c->delivered_amount : null,
            'Diferencia' => fn ($c) => $c->difference !== null ? (float) $c->difference : null,
            'Estado' => fn ($c) => Catalog::label('cash_cut_status', $c->displayStatus()), 'Concilió' => 'reconciledBy.name',
        ], $rows, 'Cortes de caja');
    }

    protected function query()
    {
        return CashCut::query()->with(['collector:id,name', 'reconciledBy:id,name'])
            ->when($this->filterCollector, fn ($q) => $q->where('collector_id', $this->filterCollector))
            ->when($this->filterStatus === 'open', fn ($q) => $q->where('status', 'open')->whereNull('closed_at'))
            ->when($this->filterStatus === 'closed', fn ($q) => $q->where('status', 'open')->whereNotNull('closed_at'))
            ->when(in_array($this->filterStatus, ['delivered', 'reconciled'], true), fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterStatus === 'diff', fn ($q) => $q->whereNotNull('difference')->where('difference', '!=', 0))
            ->when($this->search, fn ($q) => $q->where('folio', 'like', '%'.$this->search.'%'));
    }

    public function render(CashCutService $service)
    {
        $collectors = User::forTenant()->role('cobrador')->orderBy('name')->get(['id', 'name', 'active']);
        $open = CashCut::query()->where('status', 'open');
        $unassigned = $collectors->sum(fn ($u) => (float) $service->unassignedQuery($u->id,
            ($last = CashCut::query()->where('collector_id', $u->id)->max('closed_at')) ? \Carbon\Carbon::parse($last) : now()->subDays(31))->sum('amount'));

        $detail = null;
        $detailPayments = collect();
        if ($this->showDetail && $this->viewId) {
            $detail = CashCut::with(['collector:id,name,phone', 'reconciledBy:id,name'])->find($this->viewId);
            $detailPayments = $detail ? Payment::query()->where('cash_cut_id', $detail->id)
                ->with('client:id,code,first_name,last_name')->orderBy('paid_at')->get() : collect();
        }

        return view('livewire.billing.cash', [
            'cuts' => $this->applySort($this->query(), 'opened_at', 'desc')->paginate($this->perPage),
            'kpi' => [
                'in_field' => (float) (clone $open)->sum('expected_amount') + $unassigned,
                'open' => (clone $open)->count(),
                'to_reconcile' => CashCut::query()->where('status', 'delivered')->count(),
                'to_reconcile_amount' => (float) CashCut::query()->where('status', 'delivered')->sum('delivered_amount'),
                'diff_month' => (float) CashCut::query()->whereNotNull('difference')->where('closed_at', '>=', now()->startOfMonth())->sum('difference'),
                'reconciled_month' => (float) CashCut::query()->where('status', 'reconciled')->where('reconciled_at', '>=', now()->startOfMonth())->sum('delivered_amount'),
            ],
            'collectors' => $collectors->pluck('name', 'id'),
            'detail' => $detail,
            'detailPayments' => $detailPayments,
            'actionCut' => $this->actionId && ($this->showDeliver || $this->showReconcile) ? CashCut::with('collector:id,name')->find($this->actionId) : null,
            'pendingForNew' => $this->showCreate && $this->newCollector ? $this->pendingFor((int) $this->newCollector, $service) : null,
        ]);
    }

    /** Vista previa de lo que entraría en un corte nuevo del cobrador. */
    protected function pendingFor(int $collectorId, CashCutService $service): array
    {
        $open = $service->openCutFor($collectorId, false);
        $from = $open ? $service->sweepFrom($open)
            : (($last = CashCut::query()->where('collector_id', $collectorId)->max('closed_at')) ? \Carbon\Carbon::parse($last) : now()->subDays(31)->startOfDay());
        $q = $service->unassignedQuery($collectorId, $from);

        return ['open' => $open, 'count' => (clone $q)->count(), 'amount' => (float) (clone $q)->sum('amount'), 'from' => $from];
    }
}
