<div>
    <x-ui.page-header :title="$r->code.' · '.$r->name" :subtitle="trim(($r->location ?? '').' '.($r->zone ? '· '.$r->zone->name : ''), ' ·')"
                      :breadcrumbs="['Red' => route('network.index'), 'Repetidores' => route('network.repeaters.index'), $r->code => null]">
        <x-ui.status catalog="device_status" :value="$r->status" />
        @can('network.manage')
            <x-ui.dropdown>
                <x-slot:trigger><x-ui.button variant="secondary" icon="ellipsis-horizontal">Acciones</x-ui.button></x-slot:trigger>
                <x-ui.dropdown-item icon="pencil-square" wire:click="editRepeater({{ $r->id }})">Editar repetidor</x-ui.dropdown-item>
                <x-ui.dropdown-item icon="arrow-path" wire:click="pollNow">Monitorear ahora</x-ui.dropdown-item>
                @if ($r->router)
                    <x-ui.dropdown-item icon="cpu-chip" :href="route('network.mikrotik.show', $r->router)">Abrir MikroTik</x-ui.dropdown-item>
                @endif
                <x-ui.dropdown-item icon="scale" :href="route('network.balancing')">Balanceo de clientes</x-ui.dropdown-item>
                @if ($isSimulated && $r->status !== 'offline')
                    <div class="my-1 border-t border-slate-100"></div>
                    <x-ui.dropdown-item icon="bolt-slash" danger wire:click="simulateDown" wire:confirm="Solo demo: se simulará la caída del repetidor para probar alertas, ticket automático y avisos. ¿Continuar?">Simular falla (demo)</x-ui.dropdown-item>
                @endif
            </x-ui.dropdown>
        @endcan
    </x-ui.page-header>

    {{-- ============ IMPACTO DE FALLA (sección 17) ============ --}}
    @if ($r->status === 'offline' || $outage)
        @php $downAt = $outage?->started_at ?? $r->down_since; $lastComm = $outage?->last_communication_at ?? $r->last_seen_at; @endphp
        <div class="mb-5 overflow-hidden rounded-xl border-2 border-danger-500 bg-white shadow-sm">
            <div class="flex flex-col gap-3 bg-danger-600 px-5 py-4 text-white sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <x-heroicon-s-exclamation-triangle class="h-8 w-8 shrink-0" />
                    <div>
                        <div class="text-lg font-extrabold tracking-wide uppercase">Repetidor {{ $r->code }} fuera de servicio</div>
                        <div class="text-xs text-white/85">{{ $outage?->cause ?? 'Sin comunicación' }}</div>
                    </div>
                </div>
                @can('network.manage')
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="white" size="sm" icon="megaphone" wire:click="$set('showNotify', true)">Notificar a clientes afectados</x-ui.button>
                        <x-ui.button variant="white" size="sm" icon="check-circle" wire:click="markRecovered" wire:confirm="¿Marcar la falla como restablecida? Se cerrará la incidencia y se resolverá la alerta.">Marcar restablecido</x-ui.button>
                    </div>
                @endcan
            </div>
            <div class="grid grid-cols-2 gap-4 p-5 md:grid-cols-3 xl:grid-cols-6">
                <div>
                    <div class="text-xs font-medium text-slate-500">Clientes afectados</div>
                    <div class="text-3xl font-extrabold text-danger-600">{{ $affected }}</div>
                </div>
                <x-ui.detail label="Zona">{{ $r->zone?->name }}</x-ui.detail>
                <x-ui.detail label="Técnico responsable">{{ $outage?->technician?->name ?? $r->technician?->name }}</x-ui.detail>
                <x-ui.detail label="Última comunicación">{{ $lastComm?->format('d/m/Y H:i') }}</x-ui.detail>
                <x-ui.detail label="Hora de caída">
                    @if ($downAt){{ $downAt->format('d/m/Y H:i') }} <span class="block text-xs font-normal text-danger-600">hace {{ $downAt->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</span>@endif
                </x-ui.detail>
                <x-ui.detail label="Tickets relacionados">
                    @forelse ($relatedTickets ?? [] as $t)
                        <a class="link block" href="{{ Route::has('tickets.show') ? route('tickets.show', $t) : '#' }}" @if (Route::has('tickets.show')) wire:navigate @endif>{{ $t->folio }}</a>
                        <span class="block text-[11px] font-normal text-slate-500">{{ \App\Support\Catalog::label('ticket_status', $t->status) }}{{ $t->technician ? ' · '.$t->technician->name : '' }}</span>
                    @empty
                        Sin tickets
                    @endforelse
                </x-ui.detail>
            </div>
        </div>
    @endif

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="card col-span-2 p-4 lg:col-span-1">
            <div class="text-xs font-medium text-slate-500">Capacidad</div>
            <div class="mt-0.5 text-lg font-bold text-navy-900">{{ $r->clientLoad() }} / {{ $r->capacity }} <span class="text-sm font-medium text-slate-500">clientes</span></div>
            <x-ui.progress :value="$util" :color="$util >= 95 ? 'danger' : ($util >= $r->alert_threshold ? 'warning' : 'success')" label class="mt-2" />
            <div class="mt-1 text-[11px] text-slate-500">Umbral de alerta {{ $r->alert_threshold }}%</div>
        </div>
        <x-ui.stat variant="soft" label="Clientes conectados" :value="$r->status === 'offline' ? 0 : $r->online_clients_count" icon="wifi" color="success" :sub="$affected.' con servicio vigente'" />
        <x-ui.stat variant="soft" label="Latencia" :value="$last?->latency_ms !== null && $r->status !== 'offline' ? number_format($last->latency_ms, 1).' ms' : '—'" icon="clock"
                   :color="($last?->latency_ms ?? 0) > tenant()->setting('network.latency_max_ms', 80) ? 'danger' : 'brand'" :sub="$last ? 'Pérdida '.pct($last->packet_loss, 2) : null" />
        <x-ui.stat variant="soft" label="Tráfico actual" :value="$last && $r->status !== 'offline' ? number_format($last->rx_bps / 1e6, 1).' Mbps' : '—'" icon="arrow-down-circle" color="teal"
                   :sub="$last ? '↑ '.number_format($last->tx_bps / 1e6, 1).' Mbps'.($r->bandwidth_mbps ? ' · enlace '.(float) $r->bandwidth_mbps.' Mbps' : '') : null" />
        <x-ui.stat variant="soft" label="Señal promedio" :value="$avgSignal ? round($avgSignal).' dBm' : '—'" icon="signal" :color="$weakSignal ? 'warning' : 'purple'" :sub="$weakSignal.' clientes con señal baja'" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="['resumen' => 'Resumen', 'clientes' => 'Clientes', 'equipos' => 'Equipos y sectores', 'fallas' => 'Historial de fallas']" />

    @if ($tab === 'resumen')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Latencia y pérdida de paquetes (24 h)">
                    <x-ui.chart :options="$charts['quality']" height="230px" />
                </x-ui.card>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <x-ui.card title="Clientes conectados (24 h)"><x-ui.chart :options="$charts['clients']" height="200px" /></x-ui.card>
                    <x-ui.card title="Tráfico (24 h)"><x-ui.chart :options="$charts['traffic']" height="200px" /></x-ui.card>
                </div>
                <x-ui.card title="Mapa del repetidor y sus clientes" :padding="false">
                    <x-ui.map id="repeater-map" :markers="$map['markers']" :circles="$map['circles']" :sectors="$map['sectors']" height="380px" fit cluster class="rounded-t-none border-0" />
                </x-ui.card>
            </div>
            <div class="space-y-5">
                <x-ui.card title="Información">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                        <x-ui.detail label="ID">{{ $r->code }}</x-ui.detail>
                        <x-ui.detail label="Torre">{{ $r->tower ? $r->tower->code.' · '.$r->tower->name : '' }}</x-ui.detail>
                        <x-ui.detail label="Zona">{{ $r->zone?->name }}</x-ui.detail>
                        <x-ui.detail label="Técnico">{{ $r->technician?->name }}</x-ui.detail>
                        <x-ui.detail label="MikroTik">
                            @if ($r->router)<a class="link" href="{{ route('network.mikrotik.show', $r->router) }}" wire:navigate>{{ $r->router->name }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="Equipo AP">
                            @if ($r->accessPoint)<a class="link" href="{{ route('network.devices.show', $r->accessPoint) }}" wire:navigate>{{ $r->accessPoint->name }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="IP de administración">{{ $r->management_ip }}</x-ui.detail>
                        <x-ui.detail label="Último ping">{{ $r->last_ping_at ? ($r->packet_loss >= 100 ? 'Sin respuesta' : number_format((float) $r->latency_ms, 1).' ms · pérdida '.(float) $r->packet_loss.'%').' · '.\Illuminate\Support\Carbon::parse($r->last_ping_at)->diffForHumans() : '' }}</x-ui.detail>
                        <x-ui.detail label="Radio de cobertura">{{ (float) $r->coverage_radius_km }} km</x-ui.detail>
                        <x-ui.detail label="Ancho de banda">{{ $r->bandwidth_mbps ? (float) $r->bandwidth_mbps.' Mbps' : '' }}</x-ui.detail>
                        <x-ui.detail label="Coordenadas" class="col-span-2">{{ $r->lat ? (float) $r->lat.', '.(float) $r->lng : '' }}</x-ui.detail>
                        <x-ui.detail label="Último contacto">{{ $r->last_seen_at?->diffForHumans() }}</x-ui.detail>
                        <x-ui.detail label="Costo mensual">{{ money($r->monthly_cost) }}</x-ui.detail>
                    </dl>
                    @if ($r->notes)<div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ $r->notes }}</div>@endif
                </x-ui.card>
                <x-ui.card title="Sectores">
                    @forelse ($r->sectors as $s)
                        <div class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                            <div><div class="font-medium text-slate-700">{{ $s->name }}</div><div class="text-xs text-slate-500">Azimut {{ $s->azimuth }}° · {{ $s->beamwidth }}° · {{ (float) $s->range_km }} km</div></div>
                            <div class="text-right text-xs text-slate-500">{{ $s->frequency }}<div>{{ $s->technology }}</div></div>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Sin sectores asociados. <a href="{{ route('network.towers.index') }}" wire:navigate class="link">Configurar en Torres</a></p>
                    @endforelse
                </x-ui.card>
            </div>
        </div>
    @endif

    @if ($tab === 'clientes')
        <x-ui.card :padding="false">
            <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
                <x-ui.search wire:model.live.debounce.300ms="clientSearch" placeholder="Nombre, código, IP, MAC, PPPoE..." class="md:col-span-2" />
                <x-ui.select wire:model.live="clientStatus" :options="config('wisp.client_status')" placeholder="Todos los estados" />
                <x-ui.select wire:model.live="clientOnline" :options="['1' => 'En línea', '0' => 'Desconectados']" placeholder="Conexión: todos" />
            </div>
            <x-ui.table>
                <x-slot:head><th>Cliente</th><th>Plan</th><th>IP / PPPoE</th><th>CPE</th><th>Señal</th><th>Estado</th><th>Última conexión</th></x-slot:head>
                @forelse ($clients as $c)
                    <tr wire:key="c{{ $c->id }}">
                        <td>
                            <a href="{{ route('clients.show', $c) }}" wire:navigate class="flex items-center gap-2.5">
                                <span @class(['h-2.5 w-2.5 shrink-0 rounded-full', 'bg-success-500' => $c->online, 'bg-slate-300' => ! $c->online])></span>
                                <span><span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $c->full_name }}</span><span class="block text-xs text-slate-500">#{{ $c->code }}</span></span>
                            </a>
                        </td>
                        <td class="text-xs">{{ $c->plan?->name }}<div class="text-slate-500">{{ $c->plan?->speedLabel() }}</div></td>
                        <td class="text-xs"><div>{{ $c->ip_address }}</div><div class="text-slate-500">{{ $c->pppoe_user }}</div></td>
                        <td class="text-xs">@if ($c->cpe)<a class="link" href="{{ route('network.devices.show', $c->cpe) }}" wire:navigate>{{ $c->cpe->name }}</a>@else — @endif</td>
                        <td class="text-xs font-semibold">
                            @if ($c->signal_dbm)<span class="{{ $c->signal_dbm < tenant()->setting('network.signal_min_dbm', -75) ? 'text-danger-600' : ($c->signal_dbm < -68 ? 'text-warning-600' : 'text-success-600') }}">{{ (float) $c->signal_dbm }} dBm</span>@else — @endif
                        </td>
                        <td><x-ui.status catalog="client_status" :value="$c->service_status" size="xs" /></td>
                        <td class="text-xs text-slate-500">{{ $c->online ? 'En línea' : ($c->last_online_at?->diffForHumans() ?? '—') }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" icon="users" title="Sin clientes" description="No hay clientes con estos filtros." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $clients->links() }}</div>
        </x-ui.card>
    @endif

    @if ($tab === 'equipos')
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-ui.stat variant="soft" label="CPE asignados" :value="$cpeTotal" icon="device-phone-mobile" color="brand" />
            <x-ui.stat variant="soft" label="CPE operativos" :value="$cpeStats['online'] ?? 0" icon="check-circle" color="success" />
            <x-ui.stat variant="soft" label="CPE con señal baja" :value="$cpeStats['warning'] ?? 0" icon="signal" color="warning" />
            <x-ui.stat variant="soft" label="CPE sin conexión" :value="$cpeStats['offline'] ?? 0" icon="signal-slash" color="danger" />
        </div>
        <x-ui.card title="Equipos de infraestructura" :padding="false">
            <x-slot:actions><x-ui.button size="sm" variant="secondary" icon="server-stack" :href="route('network.devices.index', ['repetidor' => $r->id])">Ver todos</x-ui.button></x-slot:actions>
            <x-ui.table>
                <x-slot:head><th>Equipo</th><th>Tipo</th><th>Modelo</th><th>IP / MAC</th><th>Estado</th><th>Último contacto</th></x-slot:head>
                @forelse ($devices as $d)
                    <tr wire:key="d{{ $d->id }}">
                        <td><a class="font-semibold text-slate-800 hover:text-brand-600" href="{{ $d->type === 'mikrotik' ? route('network.mikrotik.show', $d) : route('network.devices.show', $d) }}" wire:navigate>{{ $d->name }}</a>
                            <div class="text-xs text-slate-500">{{ $d->id === $r->router_device_id ? 'Router del repetidor' : ($d->id === $r->ap_device_id ? 'AP del repetidor' : '') }}</div></td>
                        <td class="text-xs">{{ config('wisp.device_types.'.$d->type) }}</td>
                        <td class="text-xs">{{ trim($d->manufacturer.' '.$d->model) ?: '—' }}</td>
                        <td class="text-xs"><div>{{ $d->ip }}</div><div class="text-slate-500">{{ $d->mac }}</div></td>
                        <td><x-ui.status catalog="device_status" :value="$d->status" size="xs" /></td>
                        <td class="text-xs text-slate-500">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="6" icon="server-stack" title="Sin equipos registrados en este repetidor" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($tab === 'fallas')
        <x-ui.card title="Historial de fallas" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Inicio</th><th>Fin</th><th>Duración</th><th class="text-right">Afectados</th><th>Causa</th><th>Técnico</th><th>Ticket</th></x-slot:head>
                @forelse ($outages as $o)
                    <tr wire:key="o{{ $o->id }}">
                        <td class="text-xs">{{ $o->started_at->format('d/m/Y H:i') }}</td>
                        <td class="text-xs">@if ($o->ended_at){{ $o->ended_at->format('d/m/Y H:i') }}@else<x-ui.badge color="danger" dot>Activa</x-ui.badge>@endif</td>
                        <td class="text-xs">{{ $o->started_at->diffForHumans($o->ended_at ?? now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</td>
                        <td class="text-right font-semibold">{{ $o->affected_clients }}</td>
                        <td class="max-w-xs text-xs text-slate-600">{{ $o->cause ?? '—' }}</td>
                        <td class="text-xs">{{ $o->technician?->name ?? '—' }}</td>
                        <td class="text-xs">
                            @if ($o->ticket)
                                <a class="link" href="{{ Route::has('tickets.show') ? route('tickets.show', $o->ticket) : '#' }}">{{ $o->ticket->folio }}</a>
                                <div class="text-slate-500">{{ \App\Support\Catalog::label('ticket_status', $o->ticket->status) }}</div>
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" icon="check-badge" title="Sin fallas registradas" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.modal wire:model="showNotify" title="Notificar a clientes afectados" size="md">
        <p class="text-sm text-slate-600">Se enviará el aviso de <b>incidencia general</b> a los <b>{{ $affected }}</b> clientes con servicio vigente del repetidor {{ $r->code }}.</p>
        <div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">“{{ str_replace('{zona}', $r->zone?->name ?? $r->name, \App\Services\Messaging\DefaultTemplates::body('outage')) }}”</div>
        <x-ui.select label="Canal" wire:model="notifyChannel" :options="config('wisp.channels')" class="mt-4" />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="paper-airplane" wire:click="notifyAffected">Enviar aviso</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @include('livewire.network.partials.repeater-form')
</div>
