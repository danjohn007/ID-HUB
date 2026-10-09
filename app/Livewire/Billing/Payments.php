<?php

namespace App\Livewire\Billing;

use App\Livewire\Billing\Concerns\RegistersPayments;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use App\Services\ReportExporter;
use App\Support\Catalog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pagos (secciones 6 y 49): listado con filtros, totales (bruto, comisión de la pasarela,
 * comisión de la plataforma, neto), exportación, registro y cancelación de pagos.
 */
#[Title('Pagos')]
class Payments extends Component
{
    use RegistersPayments, Toasts, WithTable;

    #[Url(as: 'desde', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'hasta', except: '')]
    public string $filterTo = '';

    #[Url(as: 'metodo', except: '')]
    public string $filterMethod = '';

    #[Url(as: 'cobrador', except: '')]
    public string $filterCollector = '';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'tipo', except: '')]
    public string $filterKind = 'payment';

    #[Url(as: 'canal', except: '')]
    public string $filterChannel = '';

    public bool $showCancel = false;

    public ?int $cancelId = null;

    public string $cancelReason = '';

    protected array $sortable = ['paid_at', 'amount', 'folio', 'net_amount'];

    public function mount(): void
    {
        $this->filterFrom = $this->filterFrom ?: now()->startOfMonth()->toDateString();
        $this->filterTo = $this->filterTo ?: now()->toDateString();
    }

    public function setRange(string $range): void
    {
        [$from, $to] = match ($range) {
            'hoy' => [now(), now()],
            'semana' => [now()->startOfWeek(), now()],
            'mes_anterior' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'anio' => [now()->startOfYear(), now()],
            default => [now()->startOfMonth(), now()],
        };
        $this->filterFrom = $from->toDateString();
        $this->filterTo = $to->toDateString();
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'filterMethod', 'filterCollector', 'filterStatus', 'filterChannel');
        $this->filterKind = 'payment';
        $this->setRange('mes');
    }

    #[On('payment-registered')]
    public function refreshAfterPayment(): void {}

    public function confirmCancel(int $id): void
    {
        $this->authorize('billing.manage');
        $this->cancelId = $id;
        $this->cancelReason = '';
        $this->resetValidation();
        $this->showCancel = true;
    }

    public function cancelPayment(BillingService $billing, CashCutService $cuts): void
    {
        $this->authorize('billing.manage');
        $this->validate(['cancelReason' => 'required|string|min:3|max:150'], [], ['cancelReason' => 'motivo']);
        $payment = Payment::findOrFail($this->cancelId);
        if ($payment->status !== 'completed') {
            $this->toast('El pago ya no está aplicado', 'warning');
            $this->showCancel = false;

            return;
        }
        if ($payment->cash_cut_id && $payment->cashCut?->status === 'reconciled') {
            $this->toast('El pago pertenece a un corte de caja ya conciliado; no se puede cancelar.', 'error');

            return;
        }
        $billing->cancelPayment($payment, $this->cancelReason);
        $cuts->onPaymentCancelled($payment->refresh());
        $this->showCancel = false;
        $this->toast("Pago {$payment->folio} cancelado. Se revirtió su aplicación a los cargos.", 'warning');
    }

    protected function query()
    {
        return Payment::query()
            ->with(['client:id,code,first_name,last_name', 'collector:id,name', 'receivedBy:id,name', 'cashCut:id,folio,status'])
            ->when($this->filterFrom, fn ($q) => $q->where('paid_at', '>=', $this->filterFrom.' 00:00:00'))
            ->when($this->filterTo, fn ($q) => $q->where('paid_at', '<=', $this->filterTo.' 23:59:59'))
            ->when($this->filterMethod, fn ($q) => $q->where('method', $this->filterMethod))
            ->when($this->filterCollector, fn ($q) => $q->where('collector_id', $this->filterCollector))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterKind, fn ($q) => $q->where('kind', $this->filterKind))
            ->when($this->filterChannel === 'online', fn ($q) => $q->whereIn('method', BillingService::ONLINE_METHODS))
            ->when($this->filterChannel === 'offline', fn ($q) => $q->whereNotIn('method', BillingService::ONLINE_METHODS))
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('folio', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')
                ->orWhereHas('client', fn ($c) => $c->search($this->search))));
    }

    /** Totales (sección 49). Si no se filtra por estado, solo suman los pagos aplicados. */
    protected function totals(): object
    {
        return $this->query()->reorder()->setEagerLoads([])
            ->when(! $this->filterStatus, fn ($q) => $q->where('status', 'completed'))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(amount),0) gross, COALESCE(SUM(gateway_fee),0) gateway_fee, COALESCE(SUM(platform_fee),0) platform_fee, COALESCE(SUM(net_amount),0) net')
            ->toBase()->first();
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->orderBy('paid_at')->get();
        $t = $this->totals();

        return app(ReportExporter::class)->download('pagos', $format, [
            'Folio' => 'folio', 'Fecha' => fn ($p) => $p->paid_at?->format('d/m/Y H:i'), 'Cliente' => 'client.full_name', 'Código' => 'client.code',
            'Método' => fn ($p) => $p->methodLabel(), 'Referencia' => 'reference',
            'Importe' => fn ($p) => (float) $p->amount, 'Comisión pasarela' => fn ($p) => (float) $p->gateway_fee,
            'Comisión plataforma' => fn ($p) => (float) $p->platform_fee, 'Neto' => fn ($p) => (float) $p->net_amount,
            'Cobrador' => 'collector.name', 'Recibió' => 'receivedBy.name', 'Corte' => 'cashCut.folio',
            'Estado' => fn ($p) => Catalog::label('payment_status', $p->status),
        ], $rows, 'Pagos '.\Carbon\Carbon::parse($this->filterFrom)->format('d/m/Y').' - '.\Carbon\Carbon::parse($this->filterTo)->format('d/m/Y'), [
            'Pagos aplicados' => number_format($t->n), 'Bruto' => money($t->gross), 'Comisión pasarela' => money($t->gateway_fee),
            'Comisión plataforma' => money($t->platform_fee), 'Neto WISP' => money($t->net),
        ]);
    }

    public function render()
    {
        $byMethod = $this->query()->reorder()->setEagerLoads([])->where('status', 'completed')
            ->selectRaw('method, COUNT(*) n, SUM(amount) total')->groupBy('method')->orderByDesc('total')->toBase()->get();

        return view('livewire.billing.payments', [
            'payments' => $this->applySort($this->query(), 'paid_at', 'desc')->paginate($this->perPage),
            'totals' => $this->totals(),
            'byMethod' => $byMethod,
            'collectors' => User::forTenant()->role('cobrador')->orderBy('name')->pluck('name', 'id'),
            'cancelPaymentModel' => $this->cancelId ? Payment::with('client:id,first_name,last_name,code')->find($this->cancelId) : null,
        ]);
    }
}
