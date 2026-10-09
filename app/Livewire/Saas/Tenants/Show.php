<?php

namespace App\Livewire\Saas\Tenants;

use App\Livewire\Saas\Concerns\PlatformContext;
use App\Livewire\Saas\Dashboard;
use App\Models\AuditLog;
use App\Models\SaasInvoice;
use App\Models\SaasPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SaasBillingService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Ficha de un WISP para el superadministrador (sección 3): datos fiscales y comerciales,
 * uso contra los límites del plan, consumo (clientes, equipos, pagos en línea),
 * comisiones de los últimos 12 meses, facturas SaaS y usuarios.
 */
class Show extends Component
{
    use PlatformContext;

    public Tenant $tenant;

    public function mount(Tenant $tenant): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $this->tenant = $tenant->load('saasPlan');
    }

    public function render(SaasBillingService $billing)
    {
        $t = $this->tenant;
        $count = fn (string $table, string $column) => DB::table($table)->where('tenant_id', $t->id)->whereNull('deleted_at')
            ->selectRaw("{$column} k, COUNT(*) n")->groupBy($column)->pluck('n', 'k');

        $limits = collect(Tenant::LIMIT_RESOURCES)->map(function ($def, $resource) use ($t) {
            $used = $t->usage($resource);
            $max = $t->planLimit($resource);

            return ['label' => ucfirst($def[1]), 'used' => $used, 'max' => $max, 'pct' => $max ? $used / $max * 100 : null];
        });

        $month = $billing->onlinePayments(now()->startOfMonth(), now(), $t->id)->first();

        return view('livewire.saas.tenants.show', [
            'plan' => $t->saasPlan,
            'limits' => $limits,
            'features' => SaasPlan::FEATURES,
            'statuses' => Dashboard::STATUSES,
            'clientsByStatus' => $count('clients', 'service_status'),
            'devicesByStatus' => $count('network_devices', 'status'),
            'month' => $month,
            'chart' => $this->chart($billing),
            'invoices' => SaasInvoice::query()->where('tenant_id', $t->id)->latest('period_start')->limit(12)->get(),
            'users' => User::query()->where('tenant_id', $t->id)->orderByDesc('active')->orderBy('name')->get(),
            'activity' => AuditLog::withoutGlobalScopes()->where('tenant_id', $t->id)->with('user:id,name')->latest()->limit(10)->get(),
        ])->title($t->name);
    }

    protected function chart(SaasBillingService $billing): array
    {
        $rows = $billing->platformFeesByMonth(12, $this->tenant->id);
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));

        return [
            'grid' => ['left' => 8, 'right' => 12, 'top' => 28, 'bottom' => 8, 'containLabel' => true],
            'tooltip' => ['trigger' => 'axis'],
            'legend' => ['top' => 0, 'right' => 0],
            'xAxis' => ['type' => 'category', 'data' => $months->map(fn ($m) => ucfirst($m->translatedFormat('M y')))->all(), 'axisTick' => ['show' => false]],
            // La comisión es ~2-3 % de los pagos: va en su propio eje (derecho) para que sea legible
            'yAxis' => [
                ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
                ['type' => 'value', 'splitLine' => ['show' => false]],
            ],
            'series' => [
                [
                    'name' => 'Pagos en línea', 'type' => 'bar', 'barMaxWidth' => 22, 'itemStyle' => ['color' => '#CBD5E1', 'borderRadius' => [4, 4, 0, 0]],
                    'data' => $months->map(fn ($m) => round((float) ($rows[$m->format('Y-m')]->online ?? 0), 2))->all(),
                ],
                [
                    'name' => 'Comisión WISPCONTROL', 'type' => 'line', 'yAxisIndex' => 1, 'smooth' => true, 'symbolSize' => 6,
                    'itemStyle' => ['color' => '#1A7FF5'], 'lineStyle' => ['width' => 2.5],
                    'data' => $months->map(fn ($m) => round((float) ($rows[$m->format('Y-m')]->fees ?? 0), 2))->all(),
                ],
            ],
        ];
    }
}
