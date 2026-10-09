<div>
    <x-ui.page-header title="Panel Principal" subtitle="Resumen general de tu operación WISP">
        <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 shadow-xs">
            <x-heroicon-o-calendar class="h-4 w-4" />
            {{ now()->startOfMonth()->translatedFormat('d M Y') }} - {{ now()->translatedFormat('d M Y') }}
        </div>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_330px]">
        <div class="min-w-0 space-y-5">
            {{-- KPIs --}}
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <x-ui.stat label="Clientes activos" :value="number_format($kpi['live'])" icon="users" color="brand" :trend="'+'.$kpi['new'].' este mes'" :trend-up="true" :href="route('clients.index')" />
                <x-ui.stat label="Clientes nuevos" :value="$kpi['new']" icon="user-plus" color="success" :trend="$kpi['new_change'] !== null ? ($kpi['new_change'] >= 0 ? '+' : '').$kpi['new_change'].'% vs mes anterior' : 'este mes'" :trend-up="$kpi['new_change'] !== null ? $kpi['new_change'] >= 0 : null" />
                <x-ui.stat label="Morosos" :value="$kpi['morosos']" icon="exclamation-triangle" color="danger" :sub="$kpi['morosos_pct'].'% del total'" :href="Route::has('billing.index') ? route('billing.index') : null" />
                <x-ui.stat label="MRR" :value="money($kpi['mrr'], false)" icon="currency-dollar" color="purple" :trend="$mrrChange !== null ? ($mrrChange >= 0 ? '+' : '').$mrrChange.'%' : null" :trend-up="$mrrChange !== null ? $mrrChange >= 0 : null" :href="Route::has('finance.index') ? route('finance.index') : null" />
                <x-ui.stat label="Churn" :value="pct($kpi['churn'])" icon="arrow-path" color="warning" :trend="($kpi['churn'] - $kpi['churn_prev'] >= 0 ? '+' : '').round($kpi['churn'] - $kpi['churn_prev'], 1).' pts'" :trend-up="$kpi['churn'] <= $kpi['churn_prev'] ? false : true" />
                <x-ui.stat label="Tickets abiertos" :value="$kpi['tickets_open']" icon="ticket" color="teal" :sub="$kpi['tickets_critical'].' críticos'" :href="Route::has('tickets.index') ? route('tickets.index') : null" />
                <x-ui.stat label="Repetidores" :value="$kpi['repeaters']" icon="signal" color="navy" :sub="($kpi['repeaters'] - $kpi['repeaters_alert']).' activos / '.$kpi['repeaters_alert'].' alertas'" :href="Route::has('network.repeaters.index') ? route('network.repeaters.index') : null" />
                <x-ui.stat label="Red online" :value="pct($kpi['online_pct'], 0)" icon="wifi" color="success" :sub="'Cobranza del mes '.money($kpi['collected'], false)" />
            </div>

            {{-- Mapa + estado de repetidores --}}
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
                <x-ui.card :padding="false">
                    <x-slot:title>Mapa Operativo</x-slot:title>
                    <x-slot:actions>
                        <div class="hidden items-center gap-3 text-xs text-slate-500 sm:flex">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success-600"></span>Clientes</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-navy-800"></span>Repetidores</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning-600"></span>Torres</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-brand-600"></span>Técnicos</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Fallas</span>
                        </div>
                        @if (Route::has('map.index'))<x-ui.button size="sm" variant="ghost" icon="arrows-pointing-out" :href="route('map.index')" />@endif
                    </x-slot:actions>
                    <x-ui.map id="dashboard-map" :markers="$map['markers']" :circles="$map['circles']" height="380px" fit cluster class="rounded-t-none border-0" />
                </x-ui.card>

                <x-ui.card title="Estado de Repetidores" :padding="false">
                    <div class="max-h-[392px] divide-y divide-slate-100 overflow-y-auto">
                        @foreach ($repeaters as $r)
                            @php $util = $r->utilization(); $st = $r->status === 'online' && $util >= $r->alert_threshold ? 'warning' : $r->status; @endphp
                            <a @if (Route::has('network.repeaters.show')) href="{{ route('network.repeaters.show', $r) }}" wire:navigate @endif class="grid grid-cols-[64px_1fr_56px] items-center gap-2 px-5 py-2.5 text-sm hover:bg-slate-50">
                                <span class="flex items-center gap-2 font-semibold text-slate-700">
                                    <span @class(['h-2.5 w-2.5 rounded-full', 'bg-success-600' => $st === 'online', 'bg-warning-600' => $st === 'warning', 'bg-danger-600' => $st === 'offline', 'bg-slate-400' => ! in_array($st, ['online', 'warning', 'offline'])])></span>
                                    {{ $r->code }}
                                </span>
                                <span @class(['text-xs font-semibold', 'text-success-600' => $st === 'online', 'text-warning-600' => $st === 'warning', 'text-danger-600' => $st === 'offline', 'text-slate-500' => ! in_array($st, ['online', 'warning', 'offline'])])>
                                    {{ $st === 'offline' ? 'Fuera de servicio' : ($st === 'warning' ? 'Advertencia' : 'Activo') }}
                                </span>
                                <span class="text-right text-xs font-bold {{ $st === 'offline' ? 'text-danger-600' : 'text-slate-700' }}">{{ $st === 'offline' ? '0%' : round($util).'%' }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if (Route::has('network.repeaters.index'))
                        <div class="border-t border-slate-100 px-5 py-2.5"><a href="{{ route('network.repeaters.index') }}" wire:navigate class="link text-xs">Ver todos los repetidores →</a></div>
                    @endif
                </x-ui.card>
            </div>

            {{-- Gráficas --}}
            <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                <x-ui.card>
                    <div class="text-sm font-bold text-navy-900">Ingresos (MRR)</div>
                    <div class="mt-1 text-2xl font-bold text-navy-900">{{ money($kpi['mrr'], false) }}</div>
                    @if ($mrrChange !== null)
                        <div class="text-xs font-semibold {{ $mrrChange >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $mrrChange >= 0 ? '↑' : '↓' }} {{ abs($mrrChange) }}% vs. mes anterior</div>
                    @endif
                    <x-ui.chart :options="$mrrChart" height="170px" class="mt-2" />
                </x-ui.card>

                <x-ui.card>
                    <div class="text-sm font-bold text-navy-900">Ingresos por Plan</div>
                    <div class="mt-2 grid grid-cols-[120px_1fr] items-center gap-3">
                        <div class="relative">
                            <x-ui.chart :options="$planChart" height="120px" />
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-xs font-bold text-navy-900">{{ money($kpi['mrr'], false) }}</span><span class="text-[10px] text-slate-500">MRR</span>
                            </div>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            @php $totalMrr = max(1, collect($byPlan)->sum('mrr')); @endphp
                            @foreach ($byPlan as $p)
                                <div class="grid grid-cols-[1fr_34px_64px] items-center gap-1">
                                    <span class="flex items-center gap-1.5 truncate"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $p['color'] }}"></span>{{ $p['name'] }}</span>
                                    <span class="text-slate-500">{{ round($p['mrr'] / $totalMrr * 100) }}%</span>
                                    <span class="text-right font-semibold text-slate-700">{{ money($p['mrr'], false) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="text-sm font-bold text-navy-900">Tickets por Prioridad</div>
                    <div class="mt-2 grid grid-cols-[120px_1fr] items-center gap-3">
                        <div class="relative">
                            <x-ui.chart :options="$ticketChart" height="120px" />
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-lg font-bold text-navy-900">{{ $tickets['open'] }}</span><span class="text-[10px] text-slate-500">abiertos</span>
                            </div>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            @foreach (config('wisp.ticket_priorities') as $k => $p)
                                <div class="flex items-center justify-between">
                                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ ['critica' => '#E5484D', 'alta' => '#F7881F', 'media' => '#FBBF24', 'baja' => '#22A06B'][$k] }}"></span>{{ $p[0] }}</span>
                                    <span class="font-semibold text-slate-700">{{ $tickets['by_priority'][$k] ?? 0 }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <div class="flex flex-col items-start justify-between gap-3 rounded-xl border border-brand-100 bg-brand-50/60 p-4 sm:flex-row sm:items-center">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-600 text-white"><x-heroicon-o-presentation-chart-line class="h-6 w-6" /></span>
                    <div>
                        <div class="text-sm font-bold text-navy-900">Potencia tu negocio</div>
                        <div class="text-xs text-slate-600">Usa los datos de demanda, cobertura y rentabilidad para expandir tu red sin perder el control. {{ $kpi['prospects'] }} prospectos activos.</div>
                    </div>
                </div>
                @if (Route::has('bi.index'))<x-ui.button :href="route('bi.index')" icon="arrow-right">Ver análisis de expansión</x-ui.button>@endif
            </div>
        </div>

        {{-- Columna derecha --}}
        <div class="space-y-5">
            <x-ui.card title="Alertas Inteligentes" :padding="false">
                <x-slot:actions>@if (Route::has('alerts.index'))<a href="{{ route('alerts.index') }}" wire:navigate class="link text-xs">Ver todas →</a>@endif</x-slot:actions>
                <div class="divide-y divide-slate-100">
                    @foreach ($alertGroups as $g)
                        @php
                            $bg = ['danger' => 'bg-danger-600', 'warning' => 'bg-warning-600', 'brand' => 'bg-brand-600', 'purple' => 'bg-purple-600', 'success' => 'bg-success-600'][$g['color']];
                            $tx = ['danger' => 'text-danger-600', 'warning' => 'text-warning-600', 'brand' => 'text-brand-600', 'purple' => 'text-purple-600', 'success' => 'text-success-600'][$g['color']];
                        @endphp
                        <a @if (Route::has($g['route'])) href="{{ route($g['route']) }}" wire:navigate @endif class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full {{ $bg }} text-white">
                                <x-dynamic-component :component="'heroicon-s-'.$g['icon']" class="h-5 w-5" />
                            </span>
                            <span class="text-xl font-bold {{ $tx }} w-7 text-center">{{ $g['count'] }}</span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-slate-800">{{ $g['label'] }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $g['detail'] ?: 'Sin incidencias' }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card title="Actividad Reciente">
                <ol class="relative space-y-4 border-l-2 border-slate-100 pl-5">
                    @foreach ($activity as $a)
                        @php $c = ['success' => 'bg-success-600', 'danger' => 'bg-danger-600', 'brand' => 'bg-brand-600'][$a['color']] ?? 'bg-brand-600'; @endphp
                        <li class="relative">
                            <span class="absolute top-0 -left-[31px] flex h-6 w-6 items-center justify-center rounded-full {{ $c }} text-white ring-4 ring-white">
                                <x-dynamic-component :component="'heroicon-s-'.$a['icon']" class="h-3.5 w-3.5" />
                            </span>
                            <div class="text-sm font-semibold text-slate-800">{{ $a['title'] }}</div>
                            <div class="text-xs text-slate-500">{{ $a['sub'] }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-400">{{ $a['at']?->diffForHumans() }}</div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>
    </div>
</div>
