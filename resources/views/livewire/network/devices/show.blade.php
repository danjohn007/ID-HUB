<div>
    <x-ui.page-header :title="$d->name" :subtitle="config('wisp.device_types.'.$d->type).(trim($d->manufacturer.' '.$d->model) ? ' · '.trim($d->manufacturer.' '.$d->model) : '')"
                      :breadcrumbs="['Red' => route('network.index'), 'Equipos' => route('network.devices.index'), $d->name => null]">
        <x-ui.status catalog="device_status" :value="$d->status" />
        @if ($d->type === 'mikrotik')
            <x-ui.button variant="secondary" icon="cpu-chip" :href="route('network.mikrotik.show', $d)">Consola MikroTik</x-ui.button>
        @endif
        @can('network.manage')
            <x-ui.button variant="secondary" icon="trash" wire:click="deleteDevice" wire:confirm="¿Eliminar este equipo del inventario de red?">Eliminar</x-ui.button>
            <x-ui.button icon="pencil-square" wire:click="editDevice({{ $d->id }})">Editar</x-ui.button>
        @endcan
    </x-ui.page-header>

    {{-- Cadena del equipo instalado (sección 39) --}}
    <x-ui.card title="Equipo instalado" subtitle="Cliente → Instalación → CPE → Repetidor → Torre" class="mb-5">
        @php
            $steps = [
                ['icon' => 'user', 'label' => 'Cliente', 'value' => $client ? $client->full_name : null, 'sub' => $client ? '#'.$client->code.' · '.\App\Support\Catalog::label('client_status', $client->service_status) : 'Sin cliente', 'url' => $client ? route('clients.show', $client) : null, 'on' => (bool) $client],
                ['icon' => 'clipboard-document-check', 'label' => 'Instalación', 'value' => $installation?->folio ?? ($client?->installation_date ? 'Instalado' : null), 'sub' => $installation ? \App\Support\Catalog::label('installation_status', $installation->status).($installation->completed_at ? ' · '.$installation->completed_at->format('d/m/Y') : '') : ($client?->installation_date?->format('d/m/Y') ?? 'Sin orden'), 'url' => $installation && Route::has('installations.show') ? route('installations.show', $installation) : null, 'on' => (bool) ($installation || $client?->installation_date)],
                ['icon' => 'device-phone-mobile', 'label' => in_array($d->type, ['cpe', 'antenna']) ? 'CPE / antena' : config('wisp.device_types.'.$d->type), 'value' => $d->name, 'sub' => trim(($d->mac ?? '').' '.($d->ip ? '· '.$d->ip : '')) ?: '—', 'url' => null, 'on' => true, 'current' => true],
                ['icon' => 'wifi', 'label' => 'Repetidor', 'value' => $repeater ? $repeater->code.' · '.$repeater->name : null, 'sub' => $repeater ? \App\Support\Catalog::label('device_status', $repeater->status) : 'Sin repetidor', 'url' => $repeater ? route('network.repeaters.show', $repeater) : null, 'on' => (bool) $repeater],
                ['icon' => 'building-office-2', 'label' => 'Torre', 'value' => $tower ? $tower->code.' · '.$tower->name : null, 'sub' => $tower?->height_m ? 'Altura '.(float) $tower->height_m.' m' : ($tower ? '' : 'Sin torre'), 'url' => $tower ? route('network.towers.index') : null, 'on' => (bool) $tower],
            ];
        @endphp
        <ol class="grid grid-cols-1 gap-3 md:grid-cols-5">
            @foreach ($steps as $i => $s)
                <li class="relative">
                    <{{ $s['url'] ? 'a' : 'div' }} @if ($s['url']) href="{{ $s['url'] }}" wire:navigate @endif
                        @class(['flex h-full items-start gap-3 rounded-xl border p-3 transition', 'border-brand-300 bg-brand-50' => $s['current'] ?? false, 'border-slate-200 hover:border-brand-200' => ! ($s['current'] ?? false) && $s['on'], 'border-dashed border-slate-200 opacity-60' => ! $s['on']])>
                        <span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', 'bg-brand-600 text-white' => $s['current'] ?? false, 'bg-slate-100 text-slate-600' => ! ($s['current'] ?? false)])>
                            <x-dynamic-component :component="'heroicon-o-'.$s['icon']" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-[11px] font-bold tracking-wide text-slate-500 uppercase">{{ $s['label'] }}</span>
                            <span class="block truncate text-sm font-semibold text-slate-800">{{ $s['value'] ?? '—' }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ $s['sub'] }}</span>
                        </span>
                    </{{ $s['url'] ? 'a' : 'div' }}>
                    @if (! $loop->last)
                        <x-heroicon-m-chevron-right class="absolute top-1/2 -right-3 z-10 hidden h-4 w-4 -translate-y-1/2 text-slate-400 md:block" />
                    @endif
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        @if ($d->signal_dbm !== null || in_array($d->type, ['cpe', 'antenna']))
            <x-ui.stat variant="soft" label="Señal" :value="$d->signal_dbm !== null ? (float) $d->signal_dbm.' dBm' : '—'" icon="signal" :color="$d->signal_dbm !== null && $d->signal_dbm < tenant()->setting('network.signal_min_dbm', -75) ? 'danger' : 'success'" :sub="'Mínimo aceptable '.tenant()->setting('network.signal_min_dbm', -75).' dBm'" />
        @else
            <x-ui.stat variant="soft" label="CPU / Memoria" :value="$d->cpu !== null ? round($d->cpu).'% / '.round($d->memory).'%' : '—'" icon="cpu-chip" color="purple" />
        @endif
        <x-ui.stat variant="soft" label="Latencia" :value="$d->latency_ms !== null ? (float) $d->latency_ms.' ms' : '—'" icon="clock" color="brand" :sub="$d->packet_loss !== null ? 'Pérdida '.pct($d->packet_loss, 2) : null" />
        <x-ui.stat variant="soft" label="Último contacto" :value="$d->last_seen_at?->diffForHumans() ?? '—'" icon="signal" color="teal" :sub="$d->last_seen_at?->format('d/m/Y H:i')" />
        <x-ui.stat variant="soft" label="Uptime" :value="$d->uptime ?? '—'" icon="arrow-path" color="slate" :sub="$d->firmware" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card :title="$chartSource === 'device' ? 'Monitoreo (24 h)' : 'Monitoreo del repetidor '.$repeater?->code.' (24 h)'"
                       :subtitle="$chartSource === 'repeater' ? 'El equipo no reporta métricas propias; se muestra el enlace al que está conectado.' : null">
                @if ($charts)
                    <x-ui.chart :options="$charts['quality']" height="220px" />
                    <x-ui.chart :options="$charts['second']" height="200px" class="mt-4" />
                @else
                    <x-ui.empty icon="chart-bar" title="Sin muestras de monitoreo" description="Las métricas aparecerán tras el siguiente ciclo de monitoreo." />
                @endif
            </x-ui.card>

            @if ($client)
                <x-ui.card title="Eventos de conexión recientes" :padding="false">
                    <x-ui.table>
                        <x-slot:head><th>Fecha</th><th>Evento</th><th>Detalle</th></x-slot:head>
                        @forelse ($events as $e)
                            <tr><td class="text-xs">{{ $e->occurred_at->format('d/m/Y H:i') }}</td>
                                <td><x-ui.badge :color="$e->type === 'outage' ? 'danger' : 'warning'" size="xs">{{ ['disconnect' => 'Desconexión', 'outage' => 'Falla de repetidor', 'mac_change' => 'Cambio de MAC'][$e->type] ?? $e->type }}</x-ui.badge></td>
                                <td class="text-xs text-slate-500">{{ $e->detail ?? '—' }}</td></tr>
                        @empty
                            <x-ui.empty :colspan="3" icon="check-circle" title="Sin desconexiones registradas" />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
            @endif

            @if ($servedRepeaters->isNotEmpty() || $d->children->isNotEmpty())
                <x-ui.card title="Equipos y repetidores dependientes" :padding="false">
                    <div class="divide-y divide-slate-100">
                        @foreach ($servedRepeaters as $sr)
                            <a href="{{ route('network.repeaters.show', $sr) }}" wire:navigate class="flex items-center justify-between px-5 py-2.5 text-sm hover:bg-slate-50">
                                <span><b>{{ $sr->code }}</b> · {{ $sr->name }} <span class="text-xs text-slate-500">({{ (int) $sr->ap_device_id === $d->id ? 'como AP' : 'como router' }})</span></span>
                                <span class="text-xs text-slate-500">{{ $sr->clientLoad() }} / {{ $sr->capacity }} clientes</span>
                            </a>
                        @endforeach
                        @foreach ($d->children as $ch)
                            <a href="{{ route('network.devices.show', $ch) }}" wire:navigate class="flex items-center justify-between px-5 py-2.5 text-sm hover:bg-slate-50">
                                <span>{{ $ch->name }} <span class="text-xs text-slate-500">{{ config('wisp.device_types.'.$ch->type) }}</span></span>
                                <x-ui.status catalog="device_status" :value="$ch->status" size="xs" />
                            </a>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>

        <div class="space-y-5">
            <x-ui.card title="Datos del equipo">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <x-ui.detail label="Tipo">{{ config('wisp.device_types.'.$d->type) }}</x-ui.detail>
                    <x-ui.detail label="Fabricante">{{ $d->manufacturer }}</x-ui.detail>
                    <x-ui.detail label="Modelo">{{ $d->model }}</x-ui.detail>
                    <x-ui.detail label="Serie">{{ $d->serial }}</x-ui.detail>
                    <x-ui.detail label="MAC"><span class="font-mono text-xs">{{ $d->mac }}</span></x-ui.detail>
                    <x-ui.detail label="IP">{{ $d->ip }}</x-ui.detail>
                    <x-ui.detail label="Firmware">{{ $d->firmware }}</x-ui.detail>
                    <x-ui.detail label="Instalación">{{ $d->installed_at?->format('d/m/Y') }}</x-ui.detail>
                    <x-ui.detail label="Responsable">{{ $d->responsible?->name }}</x-ui.detail>
                    <x-ui.detail label="Conectado a">
                        @if ($d->parent)<a class="link" href="{{ route('network.devices.show', $d->parent) }}" wire:navigate>{{ $d->parent->name }}</a>@endif
                    </x-ui.detail>
                    <x-ui.detail label="Ubicación" class="col-span-2">{{ $d->location }}</x-ui.detail>
                    <x-ui.detail label="Coordenadas" class="col-span-2">{{ $d->lat ? (float) $d->lat.', '.(float) $d->lng : '' }}</x-ui.detail>
                </dl>
                @if ($d->last_error)<div class="mt-3 rounded-lg bg-danger-50 p-3 text-xs text-danger-700">Último error: {{ $d->last_error }}</div>@endif
                @if ($d->notes)<div class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ $d->notes }}</div>@endif
            </x-ui.card>

            @php $lat = $d->lat ?? $client?->lat; $lng = $d->lng ?? $client?->lng; @endphp
            @if ($lat)
                <x-ui.card title="Ubicación" :padding="false">
                    <x-ui.map id="device-map" height="260px" :center="[(float) $lat, (float) $lng]" :zoom="15" class="rounded-t-none border-0"
                              :markers="array_values(array_filter([
                                  ['lat' => (float) $lat, 'lng' => (float) $lng, 'type' => 'device', 'status' => in_array($d->status, ['online', 'warning', 'offline']) ? $d->status : 'unknown', 'popup' => e($d->name)],
                                  $repeater?->lat ? ['lat' => (float) $repeater->lat, 'lng' => (float) $repeater->lng, 'type' => 'repeater', 'status' => $repeater->status, 'popup' => e($repeater->code)] : null,
                              ]))"
                              :lines="$repeater?->lat ? [['points' => [[(float) $lat, (float) $lng], [(float) $repeater->lat, (float) $repeater->lng]], 'dash' => true, 'weight' => 2]] : []" />
                </x-ui.card>
            @endif
        </div>
    </div>

    @include('livewire.network.partials.device-form')
</div>
