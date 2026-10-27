<div>
    @php
        $bytes = function ($b) {
            $b = (float) $b; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
            while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
            return number_format($b, $i ? 1 : 0).' '.$u[$i];
        };
        $isSim = ! $d->api_mode || $d->api_mode === 'simulated';
    @endphp
    <x-ui.page-header :title="$d->name" :subtitle="($d->model ?: 'MikroTik').' · '.($d->api_host ?: $d->ip ?: 'sin IP').' · '.config('wisp.api_modes.'.($d->api_mode ?: 'simulated'))"
                      :breadcrumbs="['Red' => route('network.index'), 'MikroTik' => route('network.mikrotik.index'), $d->name => null]">
        <x-ui.status catalog="device_status" :value="$d->status" />
        <x-ui.button variant="secondary" icon="signal" wire:click="test">Probar conexión</x-ui.button>
        <x-ui.button variant="secondary" icon="pencil-square" wire:click="editRouter({{ $d->id }})">Editar</x-ui.button>
        <x-ui.button icon="arrow-path" wire:click="sync">Sincronizar</x-ui.button>
    </x-ui.page-header>

    @if ($isSim)
        <div class="mb-4 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2.5 text-xs text-slate-600">
            <x-heroicon-o-beaker class="h-4 w-4 shrink-0" />Modo simulador: los datos se generan a partir de los clientes registrados. Las órdenes (cortes, planes) se registran en la bitácora de simulación.
        </div>
    @endif
    @if ($d->last_error)
        <div class="mb-4 flex items-start gap-2 rounded-lg border border-danger-200 bg-danger-50 px-4 py-2.5 text-sm text-danger-700">
            <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" /><span><b>Último error:</b> {{ $d->last_error }}</span>
        </div>
    @endif

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        <x-ui.stat variant="soft" label="CPU" :value="$d->cpu !== null ? round($d->cpu).'%' : '—'" icon="cpu-chip" :color="($d->cpu ?? 0) >= 85 ? 'danger' : 'purple'" />
        <x-ui.stat variant="soft" label="Memoria" :value="$d->memory !== null ? round($d->memory).'%' : '—'" icon="circle-stack" color="warning" />
        <x-ui.stat variant="soft" label="Clientes en línea" :value="$onlineCount.' / '.$assignedCount" icon="users" color="success" />
        <x-ui.stat variant="soft" label="Repetidores" :value="$repeaters->count()" icon="wifi" color="brand" :sub="$repeaters->pluck('code')->implode(', ')" />
        <x-ui.stat variant="soft" label="Última sincronización" :value="$d->last_sync_at?->diffForHumans() ?? '—'" icon="clock" color="slate" :sub="$d->uptime ? 'Uptime '.$d->uptime : null" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="$tabs" />

    @if ($error)
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-danger-200 bg-white p-4">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-danger-50 text-danger-600"><x-heroicon-o-signal-slash class="h-5 w-5" /></span>
            <div>
                <div class="text-sm font-semibold text-slate-800">No se pudo consultar el equipo</div>
                <div class="text-sm text-danger-600">{{ $error }}</div>
                <div class="mt-1 text-xs text-slate-500">Verifica el modo de conexión, host, puerto, usuario y contraseña, y que el servicio esté habilitado en el MikroTik.</div>
            </div>
        </div>
    @endif

    {{-- ===================== ESTADO ===================== --}}
    @if ($tab === 'estado')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5">
                <x-ui.card title="Sistema">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                        <x-ui.detail label="Identidad">{{ $info['identity'] ?? '' }}</x-ui.detail>
                        <x-ui.detail label="RouterOS">{{ $res['version'] ?? ($info['version'] ?? '') }}</x-ui.detail>
                        <x-ui.detail label="Placa">{{ $res['board'] ?? ($info['board'] ?? '') }}</x-ui.detail>
                        <x-ui.detail label="Uptime">{{ $res['uptime'] ?? '' }}</x-ui.detail>
                        <x-ui.detail label="Método de servicio">{{ config('wisp.service_methods.'.$d->service_method, 'PPPoE') }}</x-ui.detail>
                        <x-ui.detail label="Lista de suspendidos">{{ $d->suspended_list ?: 'WISP_MOROSOS' }}</x-ui.detail>
                        <x-ui.detail label="Ubicación" class="col-span-2">{{ $d->location }} {{ $d->tower ? '· '.$d->tower->code : '' }}</x-ui.detail>
                    </dl>
                    @if (! empty($res))
                        <div class="mt-4 space-y-2">
                            <div class="text-xs text-slate-500">CPU</div><x-ui.progress :value="$res['cpu'] ?? 0" label />
                            <div class="text-xs text-slate-500">Memoria</div><x-ui.progress :value="$res['memory'] ?? 0" label />
                        </div>
                    @endif
                </x-ui.card>
                @if (! empty($simLog))
                    <x-ui.card title="Bitácora del simulador" subtitle="Últimas órdenes enviadas al equipo">
                        <ul class="space-y-1.5 font-mono text-2xs text-slate-600">
                            @foreach ($simLog as $l)
                                <li class="truncate"><span class="text-slate-400">{{ \Illuminate\Support\Str::after($l['at'], ' ') }}</span> {{ $l['op'] }} {{ $l['path'] }} {{ collect($l['where'] + $l['attrs'])->map(fn ($v, $k) => $k.'='.(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->implode(' ') }}</li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            </div>
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Interfaces" :padding="false">
                    <x-ui.table>
                        <x-slot:head><th>Interfaz</th><th>Tipo</th><th>Estado</th><th class="text-right">Recibido (rx)</th><th class="text-right">Enviado (tx)</th></x-slot:head>
                        @forelse ($interfaces as $i)
                            @php $running = in_array((string) ($i['running'] ?? ''), ['true', '1'], true) || ($i['running'] ?? null) === true; $disabled = in_array((string) ($i['disabled'] ?? ''), ['true', '1'], true); @endphp
                            <tr>
                                <td class="font-medium text-slate-800">{{ $i['name'] ?? '' }}</td>
                                <td class="text-xs text-slate-500">{{ $i['type'] ?? '' }}</td>
                                <td>@if ($disabled)<x-ui.badge color="slate" size="xs">Deshabilitada</x-ui.badge>@elseif ($running)<x-ui.badge color="success" size="xs" dot>Activa</x-ui.badge>@else<x-ui.badge color="danger" size="xs" dot>Sin enlace</x-ui.badge>@endif</td>
                                <td class="text-right text-xs">{{ isset($i['rx-byte']) ? $bytes($i['rx-byte']) : '—' }}</td>
                                <td class="text-right text-xs">{{ isset($i['tx-byte']) ? $bytes($i['tx-byte']) : '—' }}</td>
                            </tr>
                        @empty
                            <x-ui.empty :colspan="5" icon="server" title="Sin interfaces" />
                        @endforelse
                    </x-ui.table>
                </x-ui.card>
                @if ($charts)
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <x-ui.card title="Tráfico (24 h)"><x-ui.chart :options="$charts['traffic']" height="200px" /></x-ui.card>
                        <x-ui.card title="CPU y memoria (24 h)"><x-ui.chart :options="$charts['resources']" height="200px" /></x-ui.card>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Barra de búsqueda para listados --}}
    @if (in_array($tab, ['pppoe', 'hotspot', 'dhcp', 'conexiones', 'clientes']))
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar usuario, IP, MAC o cliente..." class="sm:w-80" />
            @if ($tab === 'clientes')
                <x-ui.select wire:model.live="filter" :options="['online' => 'En línea', 'offline' => 'Desconectados', 'activo' => 'Activos', 'moroso' => 'Morosos', 'suspendido' => 'Suspendidos']" placeholder="Todos" class="sm:w-48" />
            @elseif ($tab !== 'ips')
                <button type="button" wire:click="$set('filter', '{{ $filter === 'unregistered' ? '' : 'unregistered' }}')"
                        @class(['inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1', 'bg-warning-600 text-white ring-warning-600' => $filter === 'unregistered', 'bg-white text-slate-600 ring-slate-200' => $filter !== 'unregistered'])>
                    <x-heroicon-m-funnel class="h-3.5 w-3.5" />Solo no registrados / MAC distinta
                </button>
            @endif
            @if (isset($unregistered) && $unregistered)
                <x-ui.badge color="warning" dot>{{ $unregistered }} sin cliente registrado o con MAC distinta</x-ui.badge>
            @endif
        </div>
    @endif

    {{-- ===================== PPPoE ===================== --}}
    @if ($tab === 'pppoe')
        <x-ui.card :padding="false">
            <x-slot:title>Secrets PPPoE <span class="ml-1 text-xs font-medium text-slate-500">{{ $rows->count() }} usuarios · {{ $activeCount }} sesiones activas</span></x-slot:title>
            <x-ui.table>
                <x-slot:head><th>Usuario</th><th>Perfil</th><th>Cliente</th><th>Sesión</th><th>Estado</th></x-slot:head>
                @forelse ($rows->take(300) as $s)
                    @php $disabled = in_array((string) ($s['disabled'] ?? ''), ['true', '1', 'yes'], true); @endphp
                    <tr @class(['bg-warning-50/60' => ! $s['_client']])>
                        <td class="font-mono text-xs font-semibold text-slate-800">{{ $s['name'] ?? '' }}</td>
                        <td class="text-xs">{{ $s['profile'] ?? '' }}</td>
                        <td class="text-xs">
                            @if ($s['_client'])<a class="link" href="{{ route('clients.show', $s['_client']->id) }}" wire:navigate>#{{ $s['_client']->code }} {{ $s['_client']->full_name }}</a>
                            @else<x-ui.badge color="warning" size="xs">MAC/usuario no registrado</x-ui.badge>@endif
                        </td>
                        <td class="text-xs">
                            @if ($s['_active'])<span class="text-success-700">{{ $s['_active']['address'] ?? '' }}</span> <span class="font-mono text-slate-500">{{ $s['_active']['caller-id'] ?? '' }}</span><div class="text-slate-400">{{ $s['_active']['uptime'] ?? '' }}</div>
                            @else<span class="text-slate-400">Sin sesión</span>@endif
                        </td>
                        <td>@if ($disabled)<x-ui.badge color="danger" size="xs">Deshabilitado</x-ui.badge>@else<x-ui.badge color="success" size="xs">Habilitado</x-ui.badge>@endif</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" icon="key" title="Sin usuarios PPPoE" />
                @endforelse
            </x-ui.table>
            @if ($rows->count() > 300)<div class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Mostrando 300 de {{ $rows->count() }}. Usa la búsqueda para filtrar.</div>@endif
        </x-ui.card>
    @endif

    {{-- ===================== HOTSPOT ===================== --}}
    @if ($tab === 'hotspot')
        <x-ui.card title="Usuarios Hotspot activos" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Usuario</th><th>IP</th><th>MAC</th><th>Uptime</th><th>Cliente</th></x-slot:head>
                @forelse ($rows as $r)
                    <tr @class(['bg-warning-50/60' => ! $r['_client']])>
                        <td class="font-mono text-xs font-semibold">{{ $r['user'] ?? '' }}</td><td class="text-xs">{{ $r['address'] ?? '' }}</td>
                        <td class="font-mono text-xs">{{ $r['mac-address'] ?? '' }}</td><td class="text-xs">{{ $r['uptime'] ?? '' }}</td>
                        <td class="text-xs">@if ($r['_client'])<a class="link" href="{{ route('clients.show', $r['_client']->id) }}" wire:navigate>#{{ $r['_client']->code }}</a>@else<x-ui.badge color="warning" size="xs">No registrado</x-ui.badge>@endif</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" icon="globe-alt" title="Sin sesiones Hotspot activas" description="El equipo no reporta usuarios conectados al Hotspot." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================== DHCP ===================== --}}
    @if ($tab === 'dhcp')
        <x-ui.card :padding="false">
            <x-slot:title>Leases DHCP <span class="ml-1 text-xs font-medium text-slate-500">{{ $rows->count() }}</span></x-slot:title>
            <x-ui.table>
                <x-slot:head><th>IP</th><th>MAC</th><th>Host</th><th>Estado</th><th>Cliente</th></x-slot:head>
                @forelse ($rows->take(300) as $r)
                    <tr @class(['bg-warning-50/60' => ! $r['_client'] || $r['_mac_mismatch']])>
                        <td class="text-xs">{{ $r['address'] ?? '' }}</td>
                        <td class="font-mono text-xs">{{ $r['mac-address'] ?? '' }}</td>
                        <td class="text-xs">{{ $r['host-name'] ?? '' }}</td>
                        <td><x-ui.badge :color="($r['status'] ?? '') === 'bound' ? 'success' : 'slate'" size="xs">{{ ($r['status'] ?? '') === 'bound' ? 'Asignada' : ucfirst($r['status'] ?? '—') }}</x-ui.badge></td>
                        <td class="text-xs">
                            @if ($r['_client'])<a class="link" href="{{ route('clients.show', $r['_client']->id) }}" wire:navigate>#{{ $r['_client']->code }} {{ $r['_client']->full_name }}</a>
                                @if ($r['_mac_mismatch'])<x-ui.badge color="warning" size="xs">MAC distinta</x-ui.badge>@endif
                            @else<x-ui.badge color="warning" size="xs">MAC no registrada</x-ui.badge>@endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" icon="server" title="Sin leases DHCP" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================== IPs ===================== --}}
    @if ($tab === 'ips')
        <x-ui.card title="Direcciones IP" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Dirección</th><th>Red</th><th>Interfaz</th><th>Estado</th></x-slot:head>
                @forelse ($rows as $r)
                    <tr>
                        <td class="font-mono text-sm font-semibold">{{ $r['address'] ?? '' }}</td>
                        <td class="font-mono text-xs text-slate-500">{{ $r['network'] ?? '' }}</td>
                        <td class="text-xs">{{ $r['interface'] ?? '' }}</td>
                        <td>@if (in_array((string) ($r['disabled'] ?? ''), ['true', '1'], true))<x-ui.badge color="slate" size="xs">Deshabilitada</x-ui.badge>@else<x-ui.badge color="success" size="xs">Activa</x-ui.badge>@endif</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="4" icon="globe-alt" title="Sin direcciones" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ===================== CONEXIONES ===================== --}}
    @if ($tab === 'conexiones')
        <x-ui.card :padding="false">
            <x-slot:title>Conexiones activas <span class="ml-1 text-xs font-medium text-slate-500">{{ $rows->count() }}</span></x-slot:title>
            <x-ui.table>
                <x-slot:head><th>Tipo</th><th>Usuario</th><th>IP</th><th>MAC</th><th>Señal</th><th>Uptime</th><th>Cliente</th></x-slot:head>
                @forelse ($rows->take(400) as $r)
                    @php $sig = isset($r['_reg']['signal-strength']) ? (float) filter_var($r['_reg']['signal-strength'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) : null; @endphp
                    <tr @class(['bg-warning-50/60' => ! $r['_client'] || $r['_mac_mismatch']])>
                        <td><x-ui.badge color="slate" size="xs">{{ $r['type'] }}</x-ui.badge></td>
                        <td class="font-mono text-xs font-semibold">{{ $r['user'] }}</td>
                        <td class="text-xs">{{ $r['address'] }}</td>
                        <td class="font-mono text-xs">{{ $r['mac'] }}</td>
                        <td class="text-xs font-semibold">@if ($sig)<span class="{{ $sig < tenant()->setting('network.signal_min_dbm', -75) ? 'text-danger-600' : 'text-success-600' }}">{{ $sig }} dBm</span>@else — @endif</td>
                        <td class="text-xs text-slate-500">{{ $r['uptime'] }}</td>
                        <td class="text-xs">
                            @if ($r['_client'])<a class="link" href="{{ route('clients.show', $r['_client']->id) }}" wire:navigate>#{{ $r['_client']->code }} {{ $r['_client']->full_name }}</a>
                                @if ($r['_mac_mismatch'])<div><x-ui.badge color="warning" size="xs">MAC distinta a la registrada</x-ui.badge></div>@endif
                                @if (in_array($r['_client']->service_status, ['suspendido', 'cancelado']))<div><x-ui.badge color="danger" size="xs">Cliente {{ $r['_client']->service_status }}</x-ui.badge></div>@endif
                            @else<x-ui.badge color="warning" size="xs">MAC/usuario no registrado</x-ui.badge>@endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" icon="signal" title="Sin conexiones activas" />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-2.5 text-2xs text-slate-400">Las conexiones resaltadas requieren revisión técnica; no implican necesariamente un uso indebido.</div>
        </x-ui.card>
    @endif

    {{-- ===================== CLIENTES ASIGNADOS ===================== --}}
    @if ($tab === 'clientes')
        <x-ui.card :padding="false">
            <x-ui.table>
                <x-slot:head><th>Cliente</th><th>Repetidor</th><th>PPPoE / IP</th><th>Plan / perfil</th><th>Señal</th><th>Estado</th><th class="text-right">Acciones</th></x-slot:head>
                @forelse ($clients as $c)
                    <tr wire:key="ac{{ $c->id }}">
                        <td>
                            <a href="{{ route('clients.show', $c) }}" wire:navigate class="flex items-center gap-2.5">
                                <span @class(['h-2.5 w-2.5 shrink-0 rounded-full', 'bg-success-500' => $c->online, 'bg-slate-300' => ! $c->online])></span>
                                <span><span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $c->full_name }}</span><span class="block text-xs text-slate-500">#{{ $c->code }}</span></span>
                            </a>
                        </td>
                        <td class="text-xs">{{ $c->repeater?->code ?? '—' }}</td>
                        <td class="text-xs"><div class="font-mono">{{ $c->pppoe_user }}</div><div class="text-slate-500">{{ $c->ip_address }}</div></td>
                        <td class="text-xs">{{ $c->plan?->name }} <span class="text-slate-500">{{ $c->plan?->speedLabel() }}</span><div class="text-slate-400">{{ $c->plan?->mikrotik_profile ?: 'cola simple' }}</div></td>
                        <td class="text-xs">{{ $c->signal_dbm ? (float) $c->signal_dbm.' dBm' : '—' }}</td>
                        <td><x-ui.status catalog="client_status" :value="$c->service_status" size="xs" /></td>
                        <td class="text-right whitespace-nowrap">
                            @can('service.control')
                                @if ($c->service_status === 'suspendido')
                                    <x-ui.button size="sm" variant="success" icon="play" wire:click="reactivateClient({{ $c->id }})" wire:confirm="¿Reactivar el servicio de {{ $c->full_name }}?">Reactivar</x-ui.button>
                                @elseif (in_array($c->service_status, ['activo', 'moroso', 'en_revision']))
                                    <x-ui.button size="sm" variant="ghost" icon="pause" class="text-danger-600" wire:click="suspendClient({{ $c->id }})" wire:confirm="¿Suspender el servicio de {{ $c->full_name }}?" title="Suspender" />
                                @endif
                                <x-ui.button size="sm" variant="ghost" icon="arrows-up-down" wire:click="applyPlan({{ $c->id }})" title="Aplicar plan / velocidad" />
                                <x-ui.button size="sm" variant="ghost" icon="user-plus" wire:click="provisionClient({{ $c->id }})" title="Crear / actualizar usuario en el MikroTik" />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" icon="users" title="Sin clientes asignados" description="Asigna clientes a este MikroTik o a sus repetidores." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $clients->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ===================== AGENTE PUSH ===================== --}}
    @if ($tab === 'agente')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5">
                <x-ui.card title="Estado del agente">
                    <dl class="space-y-3">
                        <x-ui.detail label="Último reporte">
                            @if ($report && isset($report['_received_at']))
                                {{ \Carbon\Carbon::parse($report['_received_at'])->diffForHumans() }} <span class="text-xs font-normal text-slate-500">({{ $report['_received_at'] }} desde {{ $report['_ip'] ?? '—' }})</span>
                            @else
                                <span class="text-warning-600">Aún no se recibe ningún reporte</span>
                            @endif
                        </x-ui.detail>
                        <x-ui.detail label="Identidad reportada">{{ $report['identity'] ?? '' }}</x-ui.detail>
                        <x-ui.detail label="Comandos pendientes">
                            <span class="text-lg font-bold {{ $pending ? 'text-warning-600' : 'text-success-600' }}">{{ $pending }}</span>
                            <span class="text-xs font-normal text-slate-500">se aplicarán en la próxima ejecución del agente</span>
                        </x-ui.detail>
                    </dl>
                    @if ($queue)
                        <div class="mt-3 max-h-40 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-2xs text-slate-100">
                            @foreach ($queue as $line)<div class="truncate">{{ $line }}</div>@endforeach
                        </div>
                        <x-ui.button size="sm" variant="ghost" icon="trash" class="mt-2" wire:click="clearQueue" wire:confirm="¿Descartar los comandos pendientes?">Vaciar cola</x-ui.button>
                    @endif
                </x-ui.card>
                <x-ui.card title="Endpoints">
                    <div class="space-y-3 text-xs">
                        <div><div class="font-semibold text-slate-600">Reporte (POST)</div><code class="block break-all rounded bg-slate-50 p-2 text-slate-700">{{ \App\Services\Network\PushAgentScript::reportUrl($d) }}</code></div>
                        <div><div class="font-semibold text-slate-600">Comandos (GET)</div><code class="block break-all rounded bg-slate-50 p-2 text-slate-700">{{ \App\Services\Network\PushAgentScript::commandsUrl($d) }}</code></div>
                    </div>
                    <x-ui.button size="sm" variant="secondary" icon="key" class="mt-3" wire:click="regenerateToken({{ $d->id }})" wire:confirm="Se invalidará el script actual. ¿Regenerar token?">Regenerar token</x-ui.button>
                </x-ui.card>
            </div>
            <x-ui.card class="xl:col-span-2" title="Script de agente para RouterOS" subtitle="Requiere RouterOS 7.13+ y que el MikroTik tenga salida a Internet hacia este servidor.">
                <x-slot:actions>
                    <select wire:model.live="interval" class="form-input w-36 py-1.5 text-xs">
                        @foreach ([1, 2, 3, 4, 5] as $m)<option value="{{ $m }}">Cada {{ $m }} min</option>@endforeach
                    </select>
                </x-slot:actions>
                <ol class="mb-3 list-decimal space-y-1 pl-5 text-xs text-slate-600">
                    <li>Abre Winbox → <b>New Terminal</b> en el MikroTik {{ $d->name }}.</li>
                    <li>Copia y pega el script completo. Se crea el script <code>wispcontrol-agent</code> y su <code>/system scheduler</code>.</li>
                    <li>En unos segundos verás aquí el «Último reporte». Las suspensiones, reconexiones y cambios de plan se aplican en la siguiente ejecución.</li>
                </ol>
                <div x-data="{ copied: false }" class="relative">
                    <button type="button" @click="navigator.clipboard.writeText($refs.script.innerText); copied = true; setTimeout(() => copied = false, 2000)"
                            class="absolute top-2 right-2 rounded-md bg-white/10 px-2 py-1 text-xs font-semibold text-white hover:bg-white/20">
                        <span x-show="!copied">Copiar</span><span x-show="copied" x-cloak>¡Copiado!</span>
                    </button>
                    <pre x-ref="script" class="max-h-[460px] overflow-auto rounded-xl bg-navy-950 p-4 font-mono text-2xs leading-relaxed text-slate-100">{{ $script }}</pre>
                </div>
            </x-ui.card>
        </div>
    @endif

    @include('livewire.network.partials.router-form')
</div>
