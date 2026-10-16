<div>
    <x-ui.page-header title="Dashboard financiero" subtitle="Ingresos recurrentes, cobranza, cartera y comisiones de tu WISP">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex overflow-x-auto rounded-lg border border-slate-200 bg-white p-0.5 shadow-xs">
                @foreach (\App\Livewire\Finance\Index::PERIODS as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" @class([
                        'whitespace-nowrap rounded-md px-3 py-1.5 text-xs font-semibold transition',
                        'bg-navy-900 text-white' => $period === $key,
                        'text-slate-600 hover:bg-slate-50' => $period !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($period === 'rango')
                <div class="flex items-center gap-1.5">
                    <input type="date" wire:model.live.debounce.500ms="from" class="form-input w-36 py-1.5 text-xs" aria-label="Desde">
                    <span class="text-xs text-slate-400">a</span>
                    <input type="date" wire:model.live.debounce.500ms="to" class="form-input w-36 py-1.5 text-xs" aria-label="Hasta">
                </div>
            @endif
        </div>
    </x-ui.page-header>

    <div class="mb-4 flex items-center gap-2 text-xs text-slate-500">
        <x-heroicon-o-calendar class="h-4 w-4" />
        Periodo analizado: <span class="font-semibold text-slate-700">{{ $periodFrom->translatedFormat('d M Y') }} – {{ $periodTo->translatedFormat('d M Y') }}</span>
        <span wire:loading class="ml-2 text-brand-600">Actualizando…</span>
    </div>

    {{-- KPIs principales --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.kpi label="MRR" :value="money($kpi['mrr'])" icon="currency-dollar" color="purple" :trend="$kpi['mrr_change']"
                     :sub="number_format($kpi['live']).' clientes con servicio vigente'"
                     tip="Ingreso recurrente mensual: suma de la mensualidad equivalente de los clientes activos, morosos y en revisión. Es una foto del momento actual; la variación compara contra el cierre del mes anterior." />
        <x-admin.kpi label="ARR" :value="money($kpi['arr'], false)" icon="chart-bar-square" color="brand" sub="Proyección anual (MRR × 12)"
                     tip="Proyección anual de ingresos recurrentes: MRR actual multiplicado por 12. Supone que la base de clientes se mantiene igual." />
        <x-admin.kpi label="Churn" :value="pct($kpi['churn'], 2)" icon="arrow-path" color="warning" :sub="$kpi['cancellations'].' cancelaciones en el periodo'"
                     :trend="round($kpi['churn'] - $kpi['churn_prev'], 2)" trend-suffix=" pts" :trend-inverse="true"
                     tip="Tasa de cancelación: clientes cancelados en el periodo entre los clientes vigentes al inicio del periodo. Mientras más bajo, mejor." />
        <x-admin.kpi label="ARPU" :value="money($kpi['arpu'])" icon="user" color="teal" sub="Ingreso promedio por cliente"
                     tip="Average Revenue Per User: MRR dividido entre los clientes con servicio vigente. Indica cuánto aporta en promedio cada cliente al mes." />
        <x-admin.kpi label="LTV" :value="money($kpi['ltv'], false)" icon="sparkles" color="success" :sub="'Churn promedio 6 meses: '.pct($kpi['avg_churn'], 2)"
                     tip="Valor de vida del cliente: ARPU dividido entre el churn mensual promedio de los últimos 6 meses. Estima cuánto ingreso deja un cliente durante toda su permanencia." />
        <x-admin.kpi label="Ticket promedio" :value="money($kpi['avg_ticket'])" icon="receipt-percent" color="navy" :trend="$kpi['avg_ticket_change']"
                     tip="Importe promedio de cada pago recibido en el periodo (excluye bonificaciones y descuentos)." />
        <x-admin.kpi label="Morosidad" :value="pct($kpi['delinquency']['rate'])" icon="exclamation-triangle" color="danger"
                     :sub="$kpi['delinquency']['count'].' clientes · '.money($kpi['delinquency']['amount'], false).' vencido'"
                     tip="Clientes morosos + suspendidos entre los clientes facturables (situación actual). El monto es la suma de cargos con fecha límite vencida aún sin pagar." />
        <x-admin.kpi label="Recuperación de cartera" :value="pct($kpi['recovery'])" icon="arrow-uturn-left" color="success"
                     tip="Porcentaje de la cartera vencida que se cobró en el periodo: pagos aplicados a cargos vencidos ÷ (eso + saldo vencido pendiente)." />
    </div>

    {{-- Cobranza del periodo --}}
    <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-4">
        <x-ui.stat label="Cobrado en el periodo" :value="money($kpi['collected'], false)" icon="banknotes" color="success"
                   :trend="$kpi['collected_change'] !== null ? ($kpi['collected_change'] >= 0 ? '+' : '').$kpi['collected_change'].'% vs. anterior' : null"
                   :trend-up="$kpi['collected_change'] !== null ? $kpi['collected_change'] >= 0 : null" />
        <x-ui.stat label="Facturado en el periodo" :value="money($kpi['billed'], false)" icon="document-text" color="brand"
                   :sub="$kpi['collection_rate'] !== null ? 'Cobrado / facturado: '.pct($kpi['collection_rate']) : 'Sin cargos emitidos'" />
        <x-ui.stat label="Comisiones del periodo" :value="money($commissions['total_fees'], false)" icon="receipt-refund" color="purple"
                   :sub="'Gateway '.money($commissions['gateway_fee'], false).' · Plataforma '.money($commissions['platform_fee'], false)" />
        <x-ui.stat label="Clientes nuevos" :value="number_format($kpi['new'])" icon="user-plus" color="teal" :sub="$kpi['cancellations'].' bajas en el periodo'" />
    </div>

    {{-- Gráficas de 12 meses --}}
    <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
        <x-ui.card class="xl:col-span-2" title="Facturado vs. cobrado" subtitle="Últimos 12 meses · barras en $ y línea de % de cobranza">
            <x-ui.chart :options="$billedChart" height="290px" />
        </x-ui.card>
        <x-ui.card title="Tendencia de MRR" subtitle="Estimado al cierre de cada mes">
            <div class="text-2xl font-bold text-navy-900">{{ money($kpi['mrr'], false) }}</div>
            @if ($kpi['mrr_change'] !== null)
                <div class="text-xs font-semibold {{ $kpi['mrr_change'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $kpi['mrr_change'] >= 0 ? '↑' : '↓' }} {{ abs($kpi['mrr_change']) }}% vs. mes anterior</div>
            @endif
            <x-ui.chart :options="$mrrChart" height="226px" class="mt-2" />
        </x-ui.card>
    </div>

    {{-- Ingresos por zona / plan / método (dependen del periodo) --}}
    <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-3" wire:key="breakdown-{{ $periodFrom->timestamp }}-{{ $periodTo->timestamp }}">
        <x-ui.card title="Ingresos por zona" subtitle="Cobrado en el periodo">
            @if (count($byZone))
                <x-ui.chart :options="$zoneChart" height="{{ max(160, count($byZone) * 34) }}px" />
                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                    @foreach ($byZone as $z)
                        <div class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-1.5 truncate"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $z['color'] }}"></span>{{ $z['name'] }}</span>
                            <span class="text-slate-500">{{ $z['clients'] }} clientes</span>
                            <span class="font-semibold text-slate-700">{{ money($z['total'], false) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty icon="map" title="Sin cobros en el periodo" />
            @endif
        </x-ui.card>

        <x-ui.card title="Ingresos por plan" subtitle="Según el plan actual del cliente">
            @if (count($byPlan))
                @php $totalPlan = max(1, collect($byPlan)->sum('total')); @endphp
                <x-ui.chart :options="$planChart" height="170px" />
                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                    @foreach ($byPlan as $p)
                        <div class="grid grid-cols-[1fr_40px_80px] items-center gap-2">
                            <span class="flex items-center gap-1.5 truncate"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $p['color'] }}"></span>{{ $p['name'] }}</span>
                            <span class="text-right text-slate-500">{{ round($p['total'] / $totalPlan * 100) }}%</span>
                            <span class="text-right font-semibold text-slate-700">{{ money($p['total'], false) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty icon="rectangle-stack" title="Sin cobros en el periodo" />
            @endif
        </x-ui.card>

        <x-ui.card title="Ingresos por método de pago" subtitle="Efectivo, transferencias y pagos en línea">
            @if (count($byMethod))
                @php $totalMethod = max(1, collect($byMethod)->sum('total')); @endphp
                <x-ui.chart :options="$methodChart" height="170px" />
                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                    @foreach ($byMethod as $mt)
                        <div class="grid grid-cols-[1fr_auto_88px] items-center gap-3">
                            <span class="truncate">{{ $mt['label'] }}</span>
                            <span class="whitespace-nowrap text-right text-slate-500">{{ number_format($mt['n']) }} pagos</span>
                            <span class="text-right font-semibold text-slate-700">{{ money($mt['total'], false) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty icon="credit-card" title="Sin cobros en el periodo" />
            @endif
        </x-ui.card>
    </div>

    {{-- Comisiones --}}
    <x-ui.card class="mt-5" title="Comisiones de pagos en línea" subtitle="Sección 49: importe del pago, comisión del gateway, comisión ID-Hub e importe neto para tu WISP">
        <x-slot:actions>
            @if (Route::has('reports.index'))
                <x-ui.button size="sm" variant="secondary" icon="document-chart-bar" :href="route('reports.index', ['reporte' => 'pagos'])">Ver pagos</x-ui.button>
            @endif
        </x-slot:actions>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
            <x-ui.detail label="Pagos en línea">{{ number_format($commissions['count']) }}</x-ui.detail>
            <x-ui.detail label="Importe cobrado">{{ money($commissions['amount']) }}</x-ui.detail>
            <x-ui.detail label="Comisión gateway"><span class="text-danger-600">-{{ money($commissions['gateway_fee']) }}</span></x-ui.detail>
            <x-ui.detail label="Comisión ID-Hub"><span class="text-danger-600">-{{ money($commissions['platform_fee']) }}</span></x-ui.detail>
            <x-ui.detail label="Neto para el WISP"><span class="text-base font-bold text-success-600">{{ money($commissions['net']) }}</span></x-ui.detail>
        </div>
        @if ($commissions['amount'] > 0)
            <div class="mt-4">
                <div class="mb-1 flex justify-between text-xs text-slate-500">
                    <span>Neto {{ pct($commissions['net'] / $commissions['amount'] * 100) }}</span>
                    <span>Comisiones {{ pct($commissions['total_fees'] / $commissions['amount'] * 100) }}</span>
                </div>
                <div class="flex h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="bg-success-600" style="width: {{ $commissions['net'] / $commissions['amount'] * 100 }}%"></div>
                    <div class="bg-warning-600" style="width: {{ $commissions['gateway_fee'] / $commissions['amount'] * 100 }}%"></div>
                    <div class="bg-purple-600" style="width: {{ $commissions['platform_fee'] / $commissions['amount'] * 100 }}%"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-500">
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-600"></span>Neto WISP</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-600"></span>Gateway</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-purple-600"></span>Plataforma</span>
                </div>
            </div>
        @endif
    </x-ui.card>
</div>
