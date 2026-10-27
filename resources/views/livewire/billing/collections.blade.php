<div>
    <x-ui.page-header title="Cobradores" subtitle="Control de cobranza en campo: desempeño, visitas y asignación de clientes"
                      :breadcrumbs="Route::has('billing.index') ? ['Cobranza' => route('billing.index'), 'Cobradores' => null] : []">
        @if (Route::has('billing.cash'))
            @can('cash.reconcile')<x-ui.button variant="secondary" icon="calculator" :href="route('billing.cash')">Cortes de caja</x-ui.button>@endcan
        @endif
        @can('billing.manage')
            <x-ui.button icon="user-plus" wire:click="openAssign">Asignar clientes</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat label="Cobrado hoy en campo" :value="money($totals['today'], false)" icon="banknotes" color="success" />
        <x-ui.stat label="Cobrado esta semana" :value="money($totals['week'], false)" icon="calendar-days" color="brand" />
        <x-ui.stat label="Visitas hoy" :value="number_format($totals['visits_today'])" icon="map-pin" color="purple" />
        <x-ui.stat label="Deudores sin cobrador" :value="number_format($totals['unassigned'])" icon="user-minus" color="warning" sub="Asígnalos para su visita" />
    </div>

    {{-- Desempeño por cobrador --}}
    <x-ui.card title="Desempeño por cobrador" subtitle="Semana actual (desde el lunes)" :padding="false" class="mb-5">
        <x-ui.table>
            <x-slot:head>
                <th>Cobrador</th>
                <th class="text-right">Cobrado hoy</th>
                <th class="text-right">Semana</th>
                <th class="text-center">Visitas hoy / semana</th>
                <th>Efectividad</th>
                <th class="text-right">Clientes con saldo</th>
                <th class="text-right">Efectivo en mano</th>
                <th></th>
            </x-slot:head>
            @forelse ($stats as $s)
                <tr wire:key="col{{ $s['user']->id }}" @class(['opacity-60' => ! $s['user']->active])>
                    <td>
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$s['user']->name" color="purple" />
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800">{{ $s['user']->name }}</div>
                                <div class="text-xs text-slate-500">{{ $s['user']->phone ?: $s['user']->email }}
                                    @if ($s['user']->last_location_at) · GPS {{ $s['user']->last_location_at->diffForHumans() }}@endif
                                    @if (! $s['user']->active) · Inactivo @endif
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="text-right font-semibold text-success-700">{{ money($s['today']) }}</td>
                    <td class="text-right font-semibold text-slate-800">{{ money($s['week']) }}</td>
                    <td class="text-center text-sm text-slate-700">{{ $s['visits_today'] }} <span class="text-slate-400">/ {{ $s['visits_week'] }}</span>
                        @if ($s['promises'])<div class="text-2xs text-brand-600">{{ $s['promises'] }} promesas</div>@endif
                    </td>
                    <td class="w-40"><x-ui.progress :value="$s['effectiveness']" :color="$s['effectiveness'] >= 60 ? 'success' : ($s['effectiveness'] >= 35 ? 'warning' : 'danger')" label /></td>
                    <td class="text-right">
                        <div class="font-semibold text-danger-600">{{ money($s['debt']) }}</div>
                        <div class="text-2xs text-slate-500">{{ $s['debtors'] }} de {{ $s['clients'] }} asignados</div>
                    </td>
                    <td class="text-right font-semibold text-slate-800">{{ money($s['cash']) }}</td>
                    <td class="text-right">
                        <x-ui.button size="sm" variant="ghost" icon="funnel" wire:click="$set('filterCollector', '{{ $s['user']->id }}')" title="Ver sus visitas" />
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="8" icon="identification" title="No hay cobradores registrados" description="Da de alta usuarios con rol Cobrador." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
        {{-- Visitas --}}
        <x-ui.card :padding="false">
            <x-slot:title>Visitas de cobranza</x-slot:title>
            <div class="filter-bar">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Cliente..." class="md:col-span-1" />
                <x-ui.select wire:model.live="filterResult" :options="config('wisp.visit_results')" placeholder="Todos los resultados" />
                <x-ui.select wire:model.live="filterCollector" :options="$collectorOptions" placeholder="Todos los cobradores" />
                <x-ui.input type="date" wire:model.live="filterFrom" title="Desde" />
                <x-ui.input type="date" wire:model.live="filterTo" title="Hasta" />
            </div>
            <div class="flex flex-wrap gap-2 border-b border-slate-100 px-4 py-2">
                @foreach (config('wisp.visit_results') as $k => [$label, $color])
                    <x-ui.badge :color="$color" dot>{{ $label }} {{ $resultCounts[$k] ?? 0 }}</x-ui.badge>
                @endforeach
            </div>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th sort="visited_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th>
                    <th>Cliente</th>
                    <th>Cobrador</th>
                    <th>Resultado</th>
                    <x-ui.th sort="amount_due" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Adeudo</x-ui.th>
                    <th>Notas</th>
                </x-slot:head>
                @forelse ($visits as $v)
                    <tr wire:key="v{{ $v->id }}">
                        <td class="text-xs text-slate-600">{{ $v->visited_at->format('d/m/Y') }}<div class="text-slate-400">{{ $v->visited_at->format('H:i') }}</div></td>
                        <td>
                            @if ($v->client)
                                <a href="{{ route('clients.show', $v->client_id) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $v->client->full_name }}</a>
                                <div class="max-w-[220px] truncate text-xs text-slate-500">#{{ $v->client->code }} · {{ $v->client->address }}</div>
                            @endif
                        </td>
                        <td class="text-xs text-slate-700">{{ $v->collector?->name }}</td>
                        <td>
                            <x-ui.status catalog="visit_results" :value="$v->result" size="xs" />
                            @if ($v->result === 'promesa' && $v->promise_date)<div class="mt-0.5 text-2xs text-slate-500">{{ $v->promise_date->format('d/m/Y') }}{{ $v->promise_amount ? ' · '.money($v->promise_amount) : '' }}</div>@endif
                            @if ($v->payment)<div class="mt-0.5 text-2xs text-success-700">{{ $v->payment->folio }} · {{ money($v->payment->amount) }}</div>@endif
                        </td>
                        <td class="text-right text-sm font-semibold text-slate-700">{{ money($v->amount_due) }}</td>
                        <td class="text-xs text-slate-500">
                            <div class="max-w-[200px] truncate" title="{{ $v->notes }}">{{ $v->notes ?: '—' }}</div>
                            <div class="flex gap-2">
                                @if ($v->evidence_path)<a href="{{ \App\Support\Evidence::url($v->evidence_path) }}" target="_blank" class="link inline-flex items-center gap-0.5"><x-heroicon-o-photo class="h-3.5 w-3.5" />Foto</a>@endif
                                @if ($v->lat)<a href="https://www.google.com/maps?q={{ $v->lat }},{{ $v->lng }}" target="_blank" class="link inline-flex items-center gap-0.5"><x-heroicon-o-map-pin class="h-3.5 w-3.5" />GPS</a>@endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="6" icon="map-pin" title="Sin visitas" description="No hay visitas registradas con estos filtros." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $visits->links() }}</div>
        </x-ui.card>

        {{-- Mapa del día --}}
        <x-ui.card :padding="false">
            <x-slot:title>Mapa de visitas</x-slot:title>
            <x-slot:actions><x-ui.input type="date" wire:model.live="mapDate" class="w-40" /></x-slot:actions>
            <x-ui.map id="collections-map" :markers="$markers" height="460px" fit class="rounded-t-none border-0" />
            <div class="flex flex-wrap gap-3 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
                @foreach (['pago' => '#22A06B', 'promesa' => '#1A7FF5', 'cerrado' => '#F7881F', 'rechazo' => '#E5484D', 'no_localizado' => '#94A3B8'] as $k => $c)
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $c }}"></span>{{ \App\Support\Catalog::label('visit_results', $k) }}</span>
                @endforeach
                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-purple-600"></span>Cobrador</span>
            </div>
        </x-ui.card>
    </div>

    {{-- Asignación masiva --}}
    <x-ui.modal wire:model="showAssign" title="Asignar clientes a cobrador" size="lg">
        <div class="space-y-4">
            <p class="text-sm text-slate-600">Elige los clientes por filtros y el cobrador responsable. Se actualizará el cobrador de todos los clientes que coincidan.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <x-ui.select label="Zona" wire:model.live="assign.zone_id" :options="$zones" placeholder="Todas las zonas" />
                <x-ui.select label="Estado" wire:model.live="assign.status" :options="collect(config('wisp.client_status'))->only(\App\Models\Client::BILLABLE)" placeholder="Todos los facturables" />
                <x-ui.select label="Cobrador actual" wire:model.live="assign.from_collector" :options="$collectorOptions" placeholder="Cualquiera" />
                <x-ui.select label="Asignar a" wire:model="assign.collector_id" name="assign.collector_id" :options="['none' => '— Quitar cobrador —'] + $collectorOptions->all()" placeholder="Selecciona..." required />
            </div>
            <div class="space-y-2">
                <x-ui.toggle wire:model.live="assign.only_debt" label="Solo clientes con saldo (morosos / con adeudo)" />
                <x-ui.toggle wire:model.live="assign.only_unassigned" label="Solo clientes sin cobrador" />
            </div>
            <div class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-700"><b>{{ number_format($assignCount) }}</b> clientes coinciden con los filtros.</div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="applyAssign" wire:confirm="¿Asignar los clientes seleccionados?">Asignar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
