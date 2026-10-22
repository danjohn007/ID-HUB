<div>
    <x-ui.page-header :title="$u->name" :subtitle="$u->roleLabel().($u->phone ? ' · '.$u->phone : '').' · '.$u->email" :breadcrumbs="['Técnicos y cobradores' => route('staff.index', ['rol' => $isTech ? 'tecnico' : 'cobrador']), $u->name => null]">
        <x-ui.select wire:model.live="period" :options="\App\Services\Field\StaffMetrics::periods()" />
        @if ($u->phone)<x-ui.button variant="secondary" icon="phone" href="tel:{{ $u->phone }}" :navigate="false">Llamar</x-ui.button>@endif
        @if ($u->role === 'tecnico')
            @can('tickets.manage')
                <x-ui.button :variant="$u->on_call ? 'danger' : 'secondary'" icon="shield-check" wire:click="toggleOnCall">{{ $u->on_call ? 'De guardia · quitar' : 'Asignar guardia' }}</x-ui.button>
            @endcan
        @endif
        @if (Route::has('routes.index'))
            @can('routes.manage')<x-ui.button icon="truck" :href="route('routes.index', ['usuario' => $u->id, 'tipo' => $isTech ? 'tecnico' : 'cobranza'])">Planear ruta</x-ui.button>@endcan
        @endif
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @if ($isTech)
            <x-ui.stat variant="soft" label="Tickets abiertos" :value="$stats['open']" icon="ticket" color="brand" :sub="$stats['critical'] ? $stats['critical'].' críticos' : 'Sin críticos'" />
            <x-ui.stat variant="soft" label="Resueltos" :value="$stats['resolved']" icon="check-badge" color="success" />
            <x-ui.stat variant="soft" label="Resolución prom." :value="\App\Livewire\Tickets\Index::duration($stats['avg_min'])" icon="clock" color="purple" />
            <x-ui.stat variant="soft" label="Dentro de SLA" :value="$stats['sla_pct'] !== null ? pct($stats['sla_pct']) : '—'" icon="shield-check" :color="$stats['sla_pct'] === null || $stats['sla_pct'] >= 90 ? 'success' : 'warning'" />
            <x-ui.stat variant="soft" label="Instalaciones" :value="$stats['installs']" icon="wrench-screwdriver" color="teal" :sub="$stats['pending_installs'].' pendientes · pago '.money($stats['commission'], false)" />
        @else
            <x-ui.stat variant="soft" label="Cobrado" :value="money($stats['collected'])" icon="banknotes" color="success" :sub="$stats['payments'].' pagos · comisión '.money($stats['commission'], false)" />
            <x-ui.stat variant="soft" label="Visitas" :value="$stats['visits']" icon="map-pin" color="purple" />
            <x-ui.stat variant="soft" label="Efectividad" :value="$stats['effectiveness'] !== null ? pct($stats['effectiveness']) : '—'" icon="chart-bar" color="brand" sub="Visitas con pago" />
            <x-ui.stat variant="soft" label="Promesas" :value="$stats['promises']" icon="calendar-days" color="warning" />
            <x-ui.stat variant="soft" label="Cartera pendiente" :value="money($stats['pending_amount'], false)" icon="scale" color="danger" :sub="$stats['pending_clients'].' clientes'" />
        @endif
        <x-ui.stat variant="soft" label="Ruta de hoy" :value="$stats['stops_done'].' / '.$stats['stops']" icon="truck" color="navy" :sub="$route ? ($route->distance_km ? $route->distance_km.' km' : 'Paradas completadas') : 'Sin ruta planeada'" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="min-w-0 space-y-5 xl:col-span-2">
            <x-ui.card title="Ubicación y ruta de hoy" :subtitle="$u->last_location_at ? 'Última ubicación '.$u->last_location_at->diffForHumans().' ('.$u->last_location_at->format('d/m H:i').')' : 'Sin ubicación registrada'" :padding="false">
                <x-slot:actions>
                    @if ($route && $route->mapsUrl())<x-ui.button size="sm" variant="secondary" icon="map" :href="$route->mapsUrl()" :navigate="false" target="_blank">Google Maps</x-ui.button>@endif
                </x-slot:actions>
                <x-ui.map id="staff-show-map" :markers="$map['markers']" :lines="$map['lines']" height="340px" fit class="rounded-t-none border-0" />
            </x-ui.card>

            <x-ui.card :title="$isTech ? 'Tickets resueltos por día (30 días)' : 'Cobranza por día (30 días)'">
                <x-ui.chart :options="$chart" height="200px" />
            </x-ui.card>

            @if ($isTech)
                <x-ui.card title="Tickets abiertos asignados" :padding="false">
                    <x-ui.table>
                        <x-slot:head><th>Folio</th><th>Cliente / asunto</th><th>Prioridad</th><th>Estado</th><th>SLA</th></x-slot:head>
                        @forelse ($openTickets as $t)
                            <tr>
                                <td><a href="{{ route('tickets.show', $t) }}" wire:navigate class="font-semibold text-brand-600">{{ $t->folio }}</a></td>
                                <td class="max-w-xs"><div class="truncate text-sm font-medium">{{ $t->client?->full_name ?? 'Repetidor '.$t->repeater?->code }}</div><div class="truncate text-xs text-slate-500">{{ $t->subject }}</div></td>
                                <td><x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" /></td>
                                <td><x-ui.status catalog="ticket_status" :value="$t->status" size="xs" /></td>
                                <td>@include('livewire.tickets.partials.sla', ['ticket' => $t])</td>
                            </tr>
                        @empty
                            <x-ui.empty :colspan="5" icon="check-badge" title="Sin tickets abiertos" />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>

                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <x-ui.card title="Resueltos en el periodo" :padding="false">
                        <div class="divide-y divide-slate-100">
                            @forelse ($resolvedTickets as $t)
                                <a href="{{ route('tickets.show', $t) }}" wire:navigate class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm hover:bg-slate-50">
                                    <span class="min-w-0"><span class="font-semibold">{{ $t->folio }}</span><span class="block truncate text-xs text-slate-500">{{ $t->subject }} · {{ $t->client?->full_name }}</span></span>
                                    <span class="shrink-0 text-right text-xs"><span class="block text-slate-600">{{ \App\Livewire\Tickets\Index::duration($t->created_at->diffInMinutes($t->resolved_at)) }}</span>
                                        <span class="{{ $t->sla_breached ? 'text-danger-600' : 'text-success-600' }}">{{ $t->sla_breached ? 'Fuera de SLA' : 'En SLA' }}</span></span>
                                </a>
                            @empty
                                <x-ui.empty icon="ticket" title="Sin tickets resueltos" />
                            @endforelse
                        </div>
                    </x-ui.card>
                    <x-ui.card title="Instalaciones" :padding="false">
                        <div class="divide-y divide-slate-100">
                            @forelse ($installations as $o)
                                <a @if (Route::has('installations.show')) href="{{ route('installations.show', $o) }}" wire:navigate @endif class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm hover:bg-slate-50">
                                    <span class="min-w-0"><span class="font-semibold">{{ $o->folio }}</span><span class="block truncate text-xs text-slate-500">{{ $o->customerName() }} · {{ ($o->completed_at ?? $o->scheduled_at)?->format('d/m/Y H:i') ?? 'Sin fecha' }}</span></span>
                                    <x-ui.status catalog="installation_status" :value="$o->status" size="xs" />
                                </a>
                            @empty
                                <x-ui.empty icon="wrench-screwdriver" title="Sin instalaciones" />
                            @endforelse
                        </div>
                    </x-ui.card>
                </div>
            @else
                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <x-ui.card title="Pagos cobrados" :padding="false">
                        <div class="divide-y divide-slate-100">
                            @forelse ($payments as $p)
                                <div class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                                    <span class="min-w-0"><a href="{{ route('clients.show', $p->client_id) }}" wire:navigate class="block truncate font-medium hover:text-brand-600">{{ $p->client?->full_name }}</a><span class="text-xs text-slate-500">{{ $p->folio }} · {{ $p->paid_at->format('d/m H:i') }} · {{ $p->methodLabel() }}</span></span>
                                    <span class="font-semibold text-success-700">{{ money($p->amount) }}</span>
                                </div>
                            @empty
                                <x-ui.empty icon="banknotes" title="Sin cobros en el periodo" />
                            @endforelse
                        </div>
                    </x-ui.card>
                    <x-ui.card title="Visitas" :padding="false">
                        <div class="divide-y divide-slate-100">
                            @forelse ($visits as $v)
                                <div class="px-5 py-2.5 text-sm">
                                    <div class="flex items-center justify-between gap-2"><span class="truncate font-medium">{{ $v->client?->full_name }}</span><x-ui.status catalog="visit_results" :value="$v->result" size="xs" /></div>
                                    <div class="text-xs text-slate-500">{{ $v->visited_at->format('d/m H:i') }} · Adeudo {{ money($v->amount_due) }}{{ $v->notes ? ' · '.$v->notes : '' }}</div>
                                </div>
                            @empty
                                <x-ui.empty icon="map-pin" title="Sin visitas en el periodo" />
                            @endforelse
                        </div>
                    </x-ui.card>
                </div>
            @endif
        </div>

        <x-ui.card title="Ruta de hoy" :subtitle="$route ? count($stops).' paradas · '.\App\Support\Catalog::label('route_status', $route->status) : null" :padding="false">
            @if ($route)
                <ol class="divide-y divide-slate-100">
                    @foreach ($stops as $p)
                        <li class="flex items-start gap-3 px-5 py-3">
                            <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', 'bg-success-600 text-white' => $p['stop_status'] === 'done', 'bg-warning-500 text-white' => $p['stop_status'] === 'arrived', 'bg-slate-100 text-slate-600' => ! in_array($p['stop_status'], ['done', 'arrived'])])>
                                @if ($p['stop_status'] === 'done')<x-heroicon-m-check class="h-4 w-4" />@else{{ $p['sequence'] }}@endif
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-800">{{ $p['title'] }}</div>
                                <div class="truncate text-xs text-slate-500">{{ $p['subtitle'] }}</div>
                                <div class="mt-0.5 text-[11px] text-slate-400">
                                    {{ $p['arrived_at'] ? 'Llegó '.$p['arrived_at'] : '' }}{{ $p['departed_at'] ? ' · salió '.$p['departed_at'] : '' }}
                                    {{ ! $p['arrived_at'] ? 'Pendiente' : '' }}
                                </div>
                            </div>
                            <x-ui.badge :color="$p['badge'][1]" size="xs">{{ $p['badge'][0] }}</x-ui.badge>
                        </li>
                    @endforeach
                </ol>
            @else
                <x-ui.empty icon="truck" title="Sin ruta para hoy" description="Planea una ruta desde el módulo de rutas." />
            @endif
        </x-ui.card>
    </div>
</div>
