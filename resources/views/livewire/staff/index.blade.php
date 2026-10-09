<div>
    <x-ui.page-header title="Técnicos y cobradores" subtitle="Carga de trabajo, ubicación en campo y desempeño del personal">
        @if (Route::has('routes.index'))
            @can('routes.manage')<x-ui.button variant="secondary" icon="truck" :href="route('routes.index')">Planear rutas</x-ui.button>@endcan
        @endif
        @if (Route::has('users.index'))
            @can('users.manage')<x-ui.button variant="secondary" icon="key" :href="route('users.index')">Usuarios</x-ui.button>@endcan
        @endif
    </x-ui.page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-2">
            @foreach (['tecnico' => ['Técnicos', 'wrench-screwdriver'], 'cobrador' => ['Cobradores', 'banknotes']] as $k => [$label, $icon])
                <button wire:click="$set('role', '{{ $k }}')" @class(['inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $role === $k, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $role !== $k])>
                    <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" /> {{ $label }} <span class="opacity-70">{{ $counts[$k] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        <div class="flex gap-2">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar..." class="w-48" />
            <x-ui.select wire:model.live="period" :options="\App\Services\Field\StaffMetrics::periods()" />
        </div>
    </div>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        @if ($role === 'tecnico')
            <x-ui.stat label="Tickets abiertos asignados" :value="$totals['a']" icon="ticket" color="brand" />
            <x-ui.stat label="Tickets resueltos" :value="$totals['b']" icon="check-badge" color="success" :sub="\App\Services\Field\StaffMetrics::periods()[$period] ?? ''" />
            <x-ui.stat label="Instalaciones realizadas" :value="$totals['c']" icon="wrench-screwdriver" color="teal" :sub="\App\Services\Field\StaffMetrics::periods()[$period] ?? ''" />
        @else
            <x-ui.stat label="Cobrado en campo" :value="money($totals['a'], false)" icon="banknotes" color="success" :sub="\App\Services\Field\StaffMetrics::periods()[$period] ?? ''" />
            <x-ui.stat label="Visitas registradas" :value="$totals['b']" icon="map-pin" color="purple" :sub="\App\Services\Field\StaffMetrics::periods()[$period] ?? ''" />
            <x-ui.stat label="Cartera asignada pendiente" :value="money($totals['c'], false)" icon="scale" color="warning" />
        @endif
        <x-ui.stat label="Paradas de hoy" :value="$totals['done'].' / '.$totals['stops']" icon="truck" color="navy" sub="Completadas / planeadas" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div class="grid min-w-0 grid-cols-1 content-start gap-4 lg:grid-cols-2">
            @forelse ($users as $u)
                @php $s = $stats[$u->id]; @endphp
                <div wire:key="u{{ $u->id }}" @class(['card p-4', 'opacity-60' => ! $u->active])>
                    <div class="flex items-start gap-3">
                        <span class="relative">
                            <x-ui.avatar :name="$u->name" size="lg" :color="$role === 'tecnico' ? 'brand' : 'purple'" />
                            <span @class(['absolute right-0 bottom-0 h-3.5 w-3.5 rounded-full ring-2 ring-white', 'bg-success-500' => $u->last_location_at && $u->last_location_at->gt(now()->subHours(2)), 'bg-slate-300' => ! ($u->last_location_at && $u->last_location_at->gt(now()->subHours(2)))])></span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('staff.show', $u) }}" wire:navigate class="block truncate font-bold text-navy-900 hover:text-brand-600">{{ $u->name }}</a>
                            <div class="truncate text-xs text-slate-500">{{ $u->phone ?: $u->email }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($u->on_call)<x-ui.badge color="danger" dot size="xs">De guardia</x-ui.badge>@endif
                                @if (! $u->active)<x-ui.badge color="slate" size="xs">Inactivo</x-ui.badge>@endif
                                <span class="text-[11px] text-slate-400">{{ $u->last_location_at ? 'Ubicado '.$u->last_location_at->diffForHumans() : 'Sin ubicación' }}</span>
                            </div>
                        </div>
                        @if ($role === 'tecnico')
                            @can('tickets.manage')
                                <button wire:click="toggleOnCall({{ $u->id }})" title="{{ $u->on_call ? 'Quitar guardia' : 'Asignar guardia' }}"
                                        @class(['rounded-lg p-2 transition', 'bg-danger-50 text-danger-600 hover:bg-danger-100' => $u->on_call, 'text-slate-400 hover:bg-slate-100 hover:text-slate-600' => ! $u->on_call])>
                                    <x-heroicon-o-shield-check class="h-5 w-5" />
                                </button>
                            @endcan
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        @if ($role === 'tecnico')
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold {{ $s['critical'] ? 'text-danger-600' : 'text-navy-900' }}">{{ $s['open'] }}</div><div class="text-[11px] text-slate-500">Abiertos{{ $s['critical'] ? ' · '.$s['critical'].' crít.' : '' }}</div></div>
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold text-navy-900">{{ $s['resolved'] }}</div><div class="text-[11px] text-slate-500">Resueltos</div></div>
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold text-navy-900">{{ $s['installs'] }}</div><div class="text-[11px] text-slate-500">Instalaciones</div></div>
                        @else
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold text-success-700">{{ money($s['collected'], false) }}</div><div class="text-[11px] text-slate-500">Cobrado</div></div>
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold text-navy-900">{{ $s['visits'] }}</div><div class="text-[11px] text-slate-500">Visitas</div></div>
                            <div class="rounded-lg bg-slate-50 p-2"><div class="text-lg font-bold text-navy-900">{{ $s['pending_clients'] }}</div><div class="text-[11px] text-slate-500">Clientes con adeudo</div></div>
                        @endif
                    </div>

                    <dl class="mt-3 space-y-1.5 text-xs">
                        @if ($role === 'tecnico')
                            <div class="flex justify-between"><dt class="text-slate-500">Tiempo prom. de resolución</dt><dd class="font-semibold text-slate-700">{{ \App\Livewire\Tickets\Index::duration($s['avg_min']) }}</dd></div>
                            <div class="flex items-center justify-between gap-3"><dt class="shrink-0 text-slate-500">Dentro de SLA</dt>
                                <dd class="flex-1">@if ($s['sla_pct'] !== null)<x-ui.progress :value="$s['sla_pct']" :color="$s['sla_pct'] >= 90 ? 'success' : ($s['sla_pct'] >= 75 ? 'warning' : 'danger')" label height="h-1.5" />@else<span class="block text-right text-slate-400">—</span>@endif</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Instalaciones pendientes</dt><dd class="font-semibold text-slate-700">{{ $s['pending_installs'] }}</dd></div>
                        @else
                            <div class="flex justify-between"><dt class="text-slate-500">Pagos registrados</dt><dd class="font-semibold text-slate-700">{{ $s['payments'] }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Efectividad de visitas</dt><dd class="font-semibold text-slate-700">{{ $s['effectiveness'] !== null ? pct($s['effectiveness']) : '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Promesas de pago</dt><dd class="font-semibold text-slate-700">{{ $s['promises'] }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Cartera pendiente</dt><dd class="font-semibold text-warning-700">{{ money($s['pending_amount']) }}</dd></div>
                        @endif
                        <div class="flex items-center justify-between gap-3 pt-1"><dt class="shrink-0 text-slate-500">Ruta de hoy</dt>
                            <dd class="flex-1">@if ($s['has_route'])<x-ui.progress :value="$s['stops'] ? $s['stops_done'] / $s['stops'] * 100 : 0" color="brand" height="h-1.5" /><div class="text-right text-[11px] text-slate-500">{{ $s['stops_done'] }}/{{ $s['stops'] }} paradas</div>@else<span class="block text-right text-slate-400">Sin ruta</span>@endif</dd></div>
                    </dl>
                    <div class="mt-3 flex justify-end border-t border-slate-100 pt-3">
                        <a href="{{ route('staff.show', $u) }}" wire:navigate class="link text-xs">Ver detalle →</a>
                    </div>
                </div>
            @empty
                <div class="card lg:col-span-2"><x-ui.empty icon="identification" title="Sin personal registrado" description="Da de alta usuarios con rol técnico o cobrador." /></div>
            @endforelse
        </div>

        <div class="space-y-5">
            <x-ui.card title="Última ubicación" :padding="false" class="xl:sticky xl:top-20">
                <x-ui.map id="staff-map" :markers="$markers" height="460px" fit class="rounded-t-none border-0" />
                <p class="px-4 py-2.5 text-[11px] text-slate-500">La ubicación se actualiza cuando el personal registra llegadas, salidas, evidencias o pagos desde la app de campo.</p>
            </x-ui.card>
        </div>
    </div>
</div>
