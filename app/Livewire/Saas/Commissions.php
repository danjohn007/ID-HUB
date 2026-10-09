<?php

namespace App\Livewire\Saas;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Models\SaasInvoice;
use App\Models\Tenant;
use App\Services\SaasBillingService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Comisiones por pagos en línea (sección 49) y facturación mensual de la plataforma (sección 48):
 * importe, comisión del gateway, comisión WISPCONTROL y neto de cada WISP.
 */
#[Title('Comisiones')]
class Commissions extends Component
{
    use PlatformContext, Toasts;

    #[Url(as: 'mes')]
    public string $month = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    protected function period(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
    }

    public function updatedMonth(SaasBillingService $billing): void
    {
        $this->dispatch('chart-data-fees-chart', options: $this->chart($billing));
    }

    public function generateInvoices(SaasBillingService $billing): void
    {
        [$done, $skipped] = $billing->generateAll($this->period());
        $this->platformLog('created', "generó {$done} factura(s) SaaS de {$this->month}");
        $this->toast("{$done} factura(s) generadas o recalculadas".($skipped ? " · {$skipped} WISP sin plan omitidos" : ''));
    }

    public function markPaid(int $id, SaasBillingService $billing): void
    {
        $invoice = SaasInvoice::with('tenant')->findOrFail($id);
        $billing->markPaid($invoice);
        $this->platformLog('updated', "marcó pagada la factura {$invoice->folio}", $invoice, $invoice->tenant);
        $this->toast("Factura {$invoice->folio} marcada como pagada");
    }

    public function cancelInvoice(int $id): void
    {
        $invoice = SaasInvoice::with('tenant')->where('status', 'pending')->findOrFail($id);
        $invoice->update(['status' => 'cancelled']);
        $this->platformLog('updated', "canceló la factura {$invoice->folio}", $invoice, $invoice->tenant);
        $this->toast("Factura {$invoice->folio} cancelada", 'warning');
    }

    protected function chart(SaasBillingService $billing): array
    {
        $rows = $billing->platformFeesByMonth(12);
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));

        return [
            'grid' => ['left' => 8, 'right' => 12, 'top' => 16, 'bottom' => 8, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis'],
            'xAxis' => ['type' => 'category', 'data' => $months->map(fn ($m) => ucfirst($m->translatedFormat('M y')))->all(), 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
            'series' => [[
                'name' => 'Comisión WISPCONTROL', 'type' => 'bar', 'barMaxWidth' => 22, 'itemStyle' => ['color' => '#1A7FF5', 'borderRadius' => [4, 4, 0, 0]],
                'data' => $months->map(fn ($m) => round((float) ($rows[$m->format('Y-m')]->fees ?? 0), 2))->all(),
            ]],
        ];
    }

    public function render(SaasBillingService $billing)
    {
        $start = $this->period();
        $end = $start->copy()->endOfMonth();
        $payments = $billing->onlinePayments($start, $end);
        $tenants = Tenant::query()->with('saasPlan')->orderBy('name')->get();

        $rows = $tenants->map(fn ($t) => (object) [
            'tenant' => $t,
            'n' => (int) ($payments[$t->id]->n ?? 0),
            'amount' => (float) ($payments[$t->id]->amount ?? 0),
            'gateway_fee' => (float) ($payments[$t->id]->gateway_fee ?? 0),
            'platform_fee' => (float) ($payments[$t->id]->platform_fee ?? 0),
            'net' => (float) ($payments[$t->id]->net ?? 0),
        ])->sortByDesc('amount')->values();

        return view('livewire.saas.commissions', [
            'rows' => $rows,
            'totals' => [
                'n' => $rows->sum('n'), 'amount' => $rows->sum('amount'), 'gateway_fee' => $rows->sum('gateway_fee'),
                'platform_fee' => $rows->sum('platform_fee'), 'net' => $rows->sum('net'),
            ],
            'invoices' => SaasInvoice::query()->with('tenant:id,name', 'saasPlan:id,name')->whereDate('period_start', $start->toDateString())->orderBy('folio')->get(),
            'months' => collect(range(0, 17))->mapWithKeys(fn ($i) => [($m = now()->startOfMonth()->subMonths($i))->format('Y-m') => ucfirst($m->translatedFormat('F Y'))])->all(),
            'mrr' => $billing->mrr(),
            'chart' => $this->chart($billing),
        ]);
    }
}
