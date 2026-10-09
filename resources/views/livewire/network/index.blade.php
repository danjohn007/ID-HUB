<div>
    <x-ui.page-header title="Monitoreo de red" subtitle="Estado en tiempo real de MikroTik, repetidores, antenas y clientes">
        <div class="hidden items-center gap-1.5 text-xs text-slate-500 md:flex">
            <x-heroicon-o-clock class="h-4 w-4" />
            Último monitoreo {{ $lastPoll ? \Carbon\Carbon::parse($lastPoll)->diffForHumans() : 'pendiente' }}
        </div>
        @can('network.manage')
            <x-ui.button icon="arrow-path" variant="secondary" wire:click="pollNow">Monitorear ahora</x-ui.button>
        @endcan
        <x-ui.button icon="wifi" :href="route('network.repeaters.index')">Repetidores</x-ui.button>
    </x-ui.page-header>

    {{-- Fallas activas (impacto) --}}
    @foreach ($outages as $o)
        <div class="mb-4 flex flex-col gap-3 rounded-xl border border-danger-200 bg-danger-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger-600 text-white wc-pulse"><x-heroicon-s-exclamation-triangle class="h-5 w-5" /></span>
                <div>
                    <div class="text-sm font-extrabold tracking-wide text-danger-700 uppercase">Repetidor {{ $o->repeater?->code }} fuera de servicio</div>
                    <div class="text-xs text-danger-700/90">
                        <b>{{ $o->affected_clients }}</b> clientes afectados · {{ $o->zone?->name ?? 'Sin zona' }} · Técnico: {{ $o->technician?->name ?? 'sin asignar' }}
                        · Caída {{ $o->started_at->format('d/m/Y H:i') }} ({{ $o->started_at->diffForHumans() }})
                        @if ($o->ticket) · Ticket {{ $o->ticket->folio }} @endif
                    </div>
                </div>
            </div>
            @if ($o->repeater)
                <x-ui.button variant="danger" size="sm" icon="arrow-right" :href="route('network.repeaters.show', $o->repeater)">Ver impacto</x-ui.button>
            @endif
        </div>
    @endforeach

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat label="Equipos online" :value="number_format($net['devices_online'])" icon="server-stack" color="success" :sub="pct($net['online_pct'], 0).' de '.number_format($net['devices']).' equipos'" :href="route('network.devices.index')" />
        <x-ui.stat label="Equipos offline" :value="number_format($net['devices_offline'])" icon="no-symbol" color="danger" :sub="$net['repeaters_down'].' repetidor(es) caído(s)'" :href="route('network.devices.index', ['estado' => 'offline'])" />
        <x-ui.stat label="Clientes conectados" :value="number_format($net['clients_online'])" icon="wifi" color="brand" :sub="pct($liveClients ? $net['clients_online'] / $liveClients * 100 : 0, 0).' de clientes con servicio'" />
        <x-ui.stat label="Clientes desconectados" :value="number_format($net['clients_offline'])" icon="signal-slash" color="warning" :sub="$clientsAffected ? $clientsAffected.' por fallas activas' : 'Sin fallas activas'" />
    </div>
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-ui.stat variant="soft" label="Descarga actual" :value="number_format($now['rx'], 1).' Mbps'" icon="arrow-down-circle" color="brand" />
        <x-ui.stat variant="soft" label="Subida actual" :value="number_format($now['tx'], 1).' Mbps'" icon="arrow-up-circle" color="teal" />
        <x-ui.stat variant="soft" label="CPU promedio" :value="$now['cpu'] !== null ? pct($now['cpu'], 0) : '—'" icon="cpu-chip" :color="($now['cpu'] ?? 0) >= 80 ? 'danger' : 'purple'" sub="MikroTik" />
        <x-ui.stat variant="soft" label="Memoria promedio" :value="$now['memory'] !== null ? pct($now['memory'], 0) : '—'" icon="circle-stack" :color="($now['memory'] ?? 0) >= 85 ? 'danger' : 'warning'" sub="MikroTik" />
        <x-ui.stat variant="soft" label="Latencia" :value="$now['latency'] !== null ? number_format($now['latency'], 1).' ms' : '—'" icon="clock" :color="($now['latency'] ?? 0) > tenant()->setting('network.latency_max_ms', 80) ? 'danger' : 'success'" sub="Promedio repetidores" />
        <x-ui.stat variant="soft" label="Packet loss" :value="$now['loss'] !== null ? pct($now['loss'], 2) : '—'" icon="exclamation-circle" :color="($now['loss'] ?? 0) > tenant()->setting('network.packet_loss_max', 5) ? 'danger' : 'slate'" sub="Promedio repetidores" />
    </div>

    {{-- Series de tiempo --}}
    <x-ui.card class="mb-5" :padding="false">
        <x-slot:title>Últimas 24 horas</x-slot:title>
        <x-slot:actions>
            <select wire:model.live="scope" class="form-input w-56 py-1.5 text-xs">
                <option value="">Toda la red</option>
                <optgroup label="MikroTik">
                    @foreach ($routers as $r)<option value="d:{{ $r->id }}">{{ $r->name }}</option>@endforeach
                </optgroup>
                <optgroup label="Repetidores">
                    @foreach ($repeaters as $r)<option value="r:{{ $r->id }}">{{ $r->code }} · {{ $r->name }}</option>@endforeach
                </optgroup>
            </select>
        </x-slot:actions>
        <div class="grid grid-cols-1 divide-slate-100 lg:grid-cols-2 lg:divide-x">
            <div class="border-b border-slate-100 p-4">
                <div class="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">Consumo (tráfico)</div>
                <x-ui.chart id="net-traffic" :options="$charts['traffic']" height="220px" />
            </div>
            <div class="border-b border-slate-100 p-4">
                <div class="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">CPU y memoria</div>
                <x-ui.chart id="net-resources" :options="$charts['resources']" height="220px" />
            </div>
            <div class="p-4">
                <div class="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">Latencia y packet loss</div>
                <x-ui.chart id="net-quality" :options="$charts['quality']" height="220px" />
            </div>
            <div class="p-4">
                <div class="mb-1 text-xs font-bold tracking-wide text-slate-500 uppercase">Clientes conectados</div>
                <x-ui.chart id="net-clients" :options="$charts['clients']" height="220px" />
            </div>
        </div>
    </x-ui.card>

    <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <x-ui.card title="Saturación por repetidor" subtitle="Clientes asignados / capacidad. La línea marca el umbral de alerta.">
            <x-slot:actions><a href="{{ route('network.balancing') }}" wire:navigate class="link text-xs">Balanceo →</a></x-slot:actions>
            <x-ui.chart id="net-saturation" :options="$saturation" height="260px" />
        </x-ui.card>

        <x-ui.card title="Equipos con problemas" :padding="false">
            <x-slot:actions><a href="{{ route('network.devices.index', ['estado' => 'offline']) }}" wire:navigate class="link text-xs">Ver equipos →</a></x-slot:actions>
            <div class="max-h-[300px] divide-y divide-slate-100 overflow-y-auto">
                @forelse ($problemDevices as $d)
                    <a href="{{ route('network.devices.show', $d) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-2.5 hover:bg-slate-50">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-800">{{ $d->name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ config('wisp.device_types.'.$d->type) }} · {{ $d->repeater?->code ?? $d->ip }}
                                @if ($d->last_error) · <span class="text-danger-600">{{ \Illuminate\Support\Str::limit($d->last_error, 40) }}</span>@endif</span>
                        </span>
                        <x-ui.status catalog="device_status" :value="$d->status" size="xs" />
                    </a>
                @empty
                    <x-ui.empty icon="check-circle" title="Sin equipos de infraestructura con problemas" />
                @endforelse
            </div>
            <div class="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3 text-xs">
                <a href="{{ route('network.devices.index', ['tipo' => 'cpe', 'estado' => 'offline']) }}" wire:navigate><x-ui.badge color="danger" dot>{{ $cpeProblems['offline'] ?? 0 }} CPE sin conexión</x-ui.badge></a>
                <a href="{{ route('network.devices.index', ['tipo' => 'cpe', 'estado' => 'warning']) }}" wire:navigate><x-ui.badge color="warning" dot>{{ $cpeProblems['warning'] ?? 0 }} CPE con señal baja</x-ui.badge></a>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card :padding="false">
        <x-slot:title>Alertas de red y anomalías</x-slot:title>
        <x-slot:actions>
            <div class="flex gap-1 overflow-x-auto">
                <button wire:click="$set('alertGroup', '')" @class(['rounded-full px-3 py-1 text-xs font-semibold ring-1', 'bg-navy-900 text-white ring-navy-900' => $alertGroup === '', 'bg-white text-slate-600 ring-slate-200' => $alertGroup !== ''])>Todas</button>
                @foreach (\App\Livewire\Network\Index::ALERT_GROUPS as $k => [$label, $types])
                    <button wire:click="$set('alertGroup', '{{ $k }}')" @class(['whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold ring-1', 'bg-navy-900 text-white ring-navy-900' => $alertGroup === $k, 'bg-white text-slate-600 ring-slate-200' => $alertGroup !== $k])>
                        {{ $label }} <span class="opacity-70">{{ collect($types)->sum(fn ($t) => $alertCounts[$t] ?? 0) }}</span>
                    </button>
                @endforeach
            </div>
        </x-slot:actions>
        <x-ui.table>
            <x-slot:head><th>Alerta</th><th>Tipo</th><th>Severidad</th><th>Detectada</th><th></th></x-slot:head>
            @forelse ($alerts as $a)
                <tr wire:key="al{{ $a->id }}">
                    <td class="max-w-xl">
                        <div class="font-semibold text-slate-800">{{ $a->title }}</div>
                        <div class="line-clamp-2 text-xs text-slate-500">{{ $a->message }}</div>
                    </td>
                    <td class="text-xs whitespace-nowrap text-slate-600">{{ config('wisp.alert_types.'.$a->type, $a->type) }}</td>
                    <td><x-ui.status catalog="alert_severity" :value="$a->severity" size="xs" /></td>
                    <td class="text-xs whitespace-nowrap text-slate-500">{{ $a->created_at->diffForHumans() }}@if ($a->status === 'acknowledged')<div class="text-success-600">Revisada</div>@endif</td>
                    <td class="text-right whitespace-nowrap">
                        @if ($url = $a->subjectUrl())
                            <x-ui.button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$url" title="Ver" />
                        @endif
                        @can('network.manage')
                            @if ($a->status === 'open')
                                <x-ui.button size="sm" variant="ghost" icon="check" wire:click="acknowledge({{ $a->id }})" title="Marcar revisada" />
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="5" icon="shield-check" title="Sin alertas abiertas" description="La red opera sin incidencias en esta categoría." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-5 py-2.5 text-[11px] text-slate-400">Las anomalías son indicadores para revisión técnica y no implican uso indebido del servicio.</div>
    </x-ui.card>
</div>
