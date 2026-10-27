<div>
    <x-ui.page-header title="Business Intelligence" subtitle="Rentabilidad, crecimiento y oportunidades de expansión de tu red">
        @if (in_array($tab, ['zonas', 'repetidores']))
            <x-ui.select wire:model.live="period" :options="\App\Services\Analytics\BiService::periods()" class="w-44" />
        @elseif ($tab === 'crecimiento')
            <x-ui.select wire:model.live="months" :options="[12 => 'Últimos 12 meses', 24 => 'Últimos 24 meses']" class="w-44" />
        @endif
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('{{ $tab }}', 'xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('{{ $tab }}', 'csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('{{ $tab }}', 'pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
    </x-ui.page-header>

    <x-ui.tabs wire:model.live="tab" :tabs="\App\Livewire\Bi\Index::TABS" class="mb-5" />

    <div wire:loading.delay class="mb-3 text-xs text-brand-600">Calculando…</div>

    {{-- ===================================================== ZONAS --}}
    @if ($tab === 'zonas')
        @php
            $tRev = $zones->sum('revenue'); $tCost = $zones->sum('cost'); $tMargin = $tRev - $tCost;
        @endphp
        <div class="mb-4 text-xs text-slate-500">Periodo: <b class="text-slate-700">{{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }}</b> · Costos = costo de zona + torres + repetidores × {{ $periodMonths }} {{ $periodMonths === 1 ? 'mes' : 'meses' }}</div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.kpi label="Ingresos cobrados" :value="money($tRev, false)" icon="banknotes" color="brand" tip="Pagos recibidos en el periodo de clientes de cada zona." />
            <x-admin.kpi label="Costos operativos" :value="money($tCost, false)" icon="building-office-2" color="warning" tip="Costo mensual de la zona (renta, enlaces, personal) + costo de torres y repetidores ubicados en ella, multiplicado por los meses del periodo. Se edita en Configuración → Zonas y en Red." />
            <x-admin.kpi label="Margen" :value="money($tMargin, false)" icon="scale" :color="$tMargin >= 0 ? 'success' : 'danger'" :sub="$tRev > 0 ? 'Margen '.pct($tMargin / $tRev * 100) : null" tip="Ingresos cobrados menos costos operativos del periodo." />
            <x-admin.kpi label="Zona más rentable" :value="$zones->sortByDesc('margin')->first()['name'] ?? '—'" icon="trophy" color="purple" :sub="money($zones->max('margin') ?? 0, false).' de margen'" tip="Zona con mayor margen absoluto en el periodo." />
        </div>

        <x-ui.card class="mt-5" title="Ingresos vs. costos por zona" wire:key="zchart-{{ $period }}">
            <x-ui.chart :options="$zoneChart" height="280px" />
        </x-ui.card>

        <x-ui.card class="mt-5" :padding="false" title="Rentabilidad por zona">
            <x-ui.table>
                <x-slot:head>
                    <th>Zona</th><th class="text-right">Clientes</th><th class="text-right">MRR</th><th class="text-right">Ingresos</th><th class="text-right">Costos</th>
                    <th class="text-right">Margen</th><th class="text-right">Altas</th><th class="text-right">Cancelaciones</th><th>Morosidad</th><th class="text-right">Saldo vencido</th>
                </x-slot:head>
                @forelse ($zones as $z)
                    <tr wire:key="z{{ $z['id'] ?? 'none' }}">
                        <td><span class="flex items-center gap-2 font-semibold text-slate-800"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $z['color'] }}"></span>{{ $z['name'] }}</span>
                            <span class="text-xs text-slate-500">{{ $z['repeaters'] }} repetidores · ARPU {{ money($z['arpu']) }}</span></td>
                        <td class="text-right">{{ number_format($z['clients']) }}</td>
                        <td class="text-right">{{ money($z['mrr'], false) }}</td>
                        <td class="text-right font-semibold">{{ money($z['revenue'], false) }}</td>
                        <td class="text-right text-slate-600">{{ money($z['cost'], false) }}</td>
                        <td class="text-right">
                            <span class="font-bold {{ $z['margin'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ money($z['margin'], false) }}</span>
                            @if ($z['margin_pct'] !== null)<div class="text-xs text-slate-500">{{ pct($z['margin_pct']) }}</div>@endif
                        </td>
                        <td class="text-right text-success-600">+{{ $z['adds'] }}</td>
                        <td class="text-right {{ $z['cancels'] ? 'text-danger-600' : 'text-slate-400' }}">{{ $z['cancels'] }}</td>
                        <td class="min-w-[130px]"><x-ui.progress :value="$z['delinquency']" :color="$z['delinquency'] >= 20 ? 'danger' : ($z['delinquency'] >= 10 ? 'warning' : 'success')" label /></td>
                        <td class="text-right {{ $z['overdue'] > 0 ? 'text-danger-600' : '' }}">{{ money($z['overdue'], false) }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="10" icon="map" title="Sin zonas registradas" description="Crea zonas en Configuración → Zonas." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================================================== REPETIDORES --}}
    @if ($tab === 'repetidores')
        <div class="mb-4 text-xs text-slate-500">Periodo: <b class="text-slate-700">{{ $from->format('d/m/Y') }} – {{ $to->format('d/m/Y') }}</b> · Costo = costo del repetidor + parte proporcional de su torre</div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.kpi label="Repetidores" :value="$repeaters->count()" icon="wifi" color="navy" :sub="$repeaters->where('saturated', true)->count().' saturados'" />
            <x-admin.kpi label="Utilización promedio" :value="pct($repeaters->avg('utilization') ?? 0)" icon="chart-pie" color="brand" tip="Clientes conectados ÷ capacidad de cada repetidor, promediado." />
            <x-admin.kpi label="Capacidad libre" :value="number_format($repeaters->sum('spare')).' clientes'" icon="plus-circle" color="success" tip="Clientes adicionales que la red puede recibir sin exceder la capacidad declarada de los repetidores." />
            <x-admin.kpi label="Margen negativo" :value="$repeaters->where('margin', '<', 0)->count()" icon="arrow-trending-down" color="danger" tip="Repetidores cuyos ingresos cobrados del periodo no cubren su costo." />
        </div>

        <x-ui.card class="mt-5" title="Utilización vs. ingresos" subtitle="Cada punto es un repetidor · tamaño = clientes · eje Y = MRR del repetidor" wire:key="rchart-{{ $period }}">
            <x-ui.chart :options="$scatter" height="340px" />
        </x-ui.card>

        <x-ui.card class="mt-5" :padding="false" title="Rentabilidad por repetidor">
            <x-ui.table>
                <x-slot:head>
                    <th>Repetidor</th><th>Zona</th><th class="text-right">Clientes</th><th class="min-w-[150px]">Saturación</th><th class="text-right">MRR</th>
                    <th class="text-right">Ingresos</th><th class="text-right">Costo</th><th class="text-right">Margen</th><th class="text-right">Por cliente/mes</th>
                </x-slot:head>
                @forelse ($repeaters as $r)
                    <tr wire:key="r{{ $r['id'] }}">
                        <td>
                            @if (Route::has('network.repeaters.show'))
                                <a href="{{ route('network.repeaters.show', $r['id']) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $r['code'] }}</a>
                            @else
                                <span class="font-semibold text-slate-800">{{ $r['code'] }}</span>
                            @endif
                            <div class="text-xs text-slate-500">{{ $r['name'] }} @if ($r['status'] === 'offline')<x-ui.badge color="danger" size="xs">Fuera de servicio</x-ui.badge>@endif</div>
                        </td>
                        <td class="text-xs">{{ $r['zone'] ?? '—' }}</td>
                        <td class="text-right">{{ $r['clients'] }} <span class="text-xs text-slate-400">/ {{ $r['capacity'] }}</span></td>
                        <td><x-ui.progress :value="$r['utilization']" :color="$r['utilization'] >= 95 ? 'danger' : ($r['saturated'] ? 'warning' : 'success')" label /></td>
                        <td class="text-right">{{ money($r['mrr'], false) }}</td>
                        <td class="text-right font-semibold">{{ money($r['revenue'], false) }}</td>
                        <td class="text-right text-slate-600">{{ money($r['cost'], false) }}</td>
                        <td class="text-right font-bold {{ $r['margin'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ money($r['margin'], false) }}</td>
                        <td class="text-right text-xs">{{ money($r['per_client']) }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="9" icon="wifi" title="Sin repetidores registrados" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================================================== CRECIMIENTO --}}
    @if ($tab === 'crecimiento')
        @php
            $adds = $growth->sum('adds'); $cancels = $growth->sum('cancels');
            $first = $growth->first(); $last = $growth->last();
            $mrrGrowth = $first && $first['mrr'] > 0 ? round(($last['mrr'] - $first['mrr']) / $first['mrr'] * 100, 1) : null;
        @endphp
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.kpi label="Altas" :value="number_format($adds)" icon="user-plus" color="success" :sub="'Promedio '.round($adds / max(1, $growth->count()), 1).' por mes'" tip="Clientes con fecha de instalación dentro de los meses analizados." />
            <x-admin.kpi label="Bajas" :value="number_format($cancels)" icon="user-minus" color="danger" :sub="'Promedio '.round($cancels / max(1, $growth->count()), 1).' por mes'" tip="Clientes cancelados dentro de los meses analizados." />
            <x-admin.kpi label="Crecimiento neto" :value="($adds - $cancels >= 0 ? '+' : '').number_format($adds - $cancels)" icon="arrow-trending-up" color="brand" tip="Altas menos bajas." />
            <x-admin.kpi label="Crecimiento del MRR" :value="$mrrGrowth !== null ? ($mrrGrowth >= 0 ? '+' : '').pct($mrrGrowth) : '—'" icon="currency-dollar" color="purple"
                         :sub="money($first['mrr'] ?? 0, false).' → '.money($last['mrr'] ?? 0, false)" tip="Variación del MRR estimado entre el primer y el último mes del rango." />
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-2" wire:key="gcharts-{{ $months }}">
            <x-ui.card title="Altas, bajas y crecimiento neto"><x-ui.chart :options="$growthChart" height="290px" /></x-ui.card>
            <x-ui.card title="MRR y clientes vigentes"><x-ui.chart :options="$mrrChart" height="290px" /></x-ui.card>
        </div>

        <x-ui.card class="mt-5" :padding="false" title="Detalle mensual">
            <x-ui.table>
                <x-slot:head><th>Mes</th><th class="text-right">Altas</th><th class="text-right">Bajas</th><th class="text-right">Neto</th><th class="text-right">Clientes</th><th class="text-right">MRR</th><th class="text-right">Var. MRR</th><th class="text-right">Cobrado</th></x-slot:head>
                @foreach ($growth->reverse() as $g)
                    <tr wire:key="g{{ $g['key'] }}">
                        <td class="font-semibold">{{ $g['label'] }}</td>
                        <td class="text-right text-success-600">+{{ $g['adds'] }}</td>
                        <td class="text-right text-danger-600">-{{ $g['cancels'] }}</td>
                        <td class="text-right font-semibold">{{ $g['net'] >= 0 ? '+' : '' }}{{ $g['net'] }}</td>
                        <td class="text-right">{{ number_format($g['clients']) }}</td>
                        <td class="text-right">{{ money($g['mrr'], false) }}</td>
                        <td class="text-right text-xs {{ ($g['mrr_growth'] ?? 0) >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $g['mrr_growth'] !== null ? (($g['mrr_growth'] >= 0 ? '+' : '').$g['mrr_growth'].'%') : '—' }}</td>
                        <td class="text-right">{{ money($g['collected'], false) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================================================== EXPANSIÓN --}}
    @if ($tab === 'expansion')
        @php $s = $exp['summary']; $p = $exp['params']; @endphp
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-admin.kpi label="Prospectos activos" :value="number_format($s['prospects'])" icon="user-plus" color="brand" :sub="$s['recent'].' en los últimos 30 días'" />
            <x-admin.kpi label="Con cobertura" :value="number_format($s['covered'])" icon="signal" color="success" :sub="'MRR potencial '.money($s['covered_potential_mrr'], false)"
                         tip="Prospectos dentro del radio de cobertura de algún repetidor: se pueden instalar sin nueva infraestructura." />
            <x-admin.kpi label="Sin cobertura" :value="number_format($s['uncovered'])" icon="signal-slash" color="danger" :sub="'MRR potencial '.money($s['potential_mrr'], false)"
                         tip="Prospectos fuera de la cobertura actual: demanda que requiere ampliar la red." />
            <x-admin.kpi label="Zonas candidatas" :value="$s['clusters']" icon="map-pin" color="purple" :sub="'Agrupación ~'.$p['cluster_km'].' km'"
                         tip="Grupos de prospectos sin cobertura cercanos entre sí. Cada grupo se evalúa como posible punto para un nuevo repetidor." />
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            <x-ui.card :padding="false" title="Mapa de demanda y cobertura" subtitle="Calor = prospectos · verde con cobertura / rojo sin cobertura · morado = zonas candidatas">
                <x-ui.map id="bi-expansion" :markers="$exp['map']['markers']" :circles="$exp['map']['circles']" :heat="$exp['map']['heat']" height="460px" fit class="rounded-t-none border-0" />
            </x-ui.card>

            <x-ui.card title="Demanda por zona" :padding="false" subtitle="Interesados, cobertura potencial y solicitudes recientes">
                <x-ui.table>
                    <x-slot:head><th>Zona</th><th class="text-right">Interes.</th><th class="text-right">Cubiertos</th><th class="text-right">30 d</th><th class="text-right">MRR pot.</th></x-slot:head>
                    @forelse ($exp['byZone'] as $z)
                        <tr>
                            <td class="font-semibold">{{ $z['zone'] }}</td>
                            <td class="text-right">{{ $z['total'] }}</td>
                            <td class="text-right text-success-600">{{ $z['covered'] }}</td>
                            <td class="text-right">{{ $z['recent'] }}</td>
                            <td class="text-right text-xs">{{ money($z['potential_mrr'], false) }}</td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="5" icon="user-plus" title="Sin prospectos activos" />
                    @endforelse
                </x-ui.table>
                <div class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
                    MRR potencial = interesados × precio del plan deseado (o promedio {{ money($p['avg_plan']) }}) × conversión esperada {{ round($p['conversion']) }}%.
                </div>
            </x-ui.card>
        </div>

        <x-ui.card class="mt-5" title="Recomendaciones de expansión" subtitle="Ordenadas por puntaje: demanda (35) + solicitudes recientes (20) + MRR potencial (25) + cercanía a la red (10) + capacidad disponible (10)">
            <x-slot:actions>
                @can('settings.manage')
                    @if (Route::has('settings.index'))<x-ui.button size="sm" variant="ghost" icon="cog-6-tooth" :href="route('settings.index', ['tab' => 'red'])">Parámetros</x-ui.button>@endif
                @endcan
            </x-slot:actions>
            @forelse (array_slice($exp['candidates'], 0, 10) as $i => $c)
                <div class="flex flex-col gap-3 border-b border-slate-100 py-4 first:pt-0 last:border-0 last:pb-0" wire:key="cand{{ $i }}">
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-xl {{ $c['score'] >= 60 ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-700' }}">
                            <span class="text-2xs leading-none opacity-80">#{{ $i + 1 }}</span><span class="text-sm leading-tight font-bold">{{ $c['score'] }}</span>
                        </span>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-navy-900">{{ $c['recommendation'] }}</div>
                            <div class="truncate text-xs text-slate-500">{{ $c['prospects'] }}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 pl-14 text-xs sm:grid-cols-3 lg:grid-cols-6">
                        <x-ui.detail label="Interesados">{{ $c['demand'] }} <span class="text-slate-400">({{ $c['recent'] }} recientes)</span></x-ui.detail>
                        <x-ui.detail label="MRR potencial">{{ money($c['potential_mrr'], false) }}</x-ui.detail>
                        <x-ui.detail label="Red más cercana">{{ $c['nearest'] ? $c['nearest'].' · '.$c['distance_km'].' km' : '—' }}</x-ui.detail>
                        <x-ui.detail label="Capacidad libre (5 km)">{{ $c['spare_nearby'] }}</x-ui.detail>
                        <x-ui.detail label="Inversión">{{ money($c['investment'], false) }}</x-ui.detail>
                        <x-ui.detail label="Recuperación">
                            @if ($c['payback_months'])
                                <span @class(['font-bold', 'text-success-600' => $c['payback_months'] <= 12, 'text-warning-600' => $c['payback_months'] > 12 && $c['payback_months'] <= 24, 'text-danger-600' => $c['payback_months'] > 24])>{{ $c['payback_months'] }} meses</span>
                            @else — @endif
                        </x-ui.detail>
                    </div>
                </div>
            @empty
                <x-ui.empty icon="check-badge" title="Toda la demanda registrada tiene cobertura" description="No hay prospectos fuera del radio de cobertura de tus repetidores." />
            @endforelse
            <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                Recuperación estimada = inversión ÷ MRR potencial. Inversión de repetidor nuevo {{ money($p['repeater_cost'], false) }} · ampliación de sector {{ money($p['sector_cost'], false) }}.
                Es un indicador de apoyo para la decisión, no un estudio de factibilidad técnica (línea de vista, permisos, backhaul).
            </p>
        </x-ui.card>
    @endif
</div>
