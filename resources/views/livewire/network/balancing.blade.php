<div>
    <x-ui.page-header title="Balanceo de clientes" subtitle="Detecta repetidores saturados y clientes mal distribuidos. La decisión final es del administrador.">
        <x-ui.toggle wire:model.live="applyNetwork" label="Aplicar en MikroTik" description="Aprovisiona al cliente en el nuevo equipo si cambia" />
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat variant="soft" label="Repetidores saturados" :value="count($a['saturated'])" icon="exclamation-triangle" :color="count($a['saturated']) ? 'danger' : 'success'" sub="En o sobre su umbral de alerta" />
        <x-ui.stat variant="soft" label="Sugerencias de migración" :value="collect($a['suggestions'])->sum(fn ($s) => count($s['clients']))" icon="arrows-right-left" color="brand" sub="clientes que podrían moverse" />
        <x-ui.stat variant="soft" label="Clientes mal distribuidos" :value="count($a['misplaced'])" icon="map-pin" color="warning" sub="Con otro repetidor más adecuado" />
        <x-ui.stat variant="soft" label="Sectores con alta utilización" :value="collect($a['sectors'])->where('high', true)->count()" icon="signal" color="purple" :sub="count($a['sectors']).' sectores con repetidor'" />
    </div>

    {{-- Sugerencias --}}
    <div class="mb-5 space-y-4">
        @forelse ($a['suggestions'] as $s)
            <x-ui.card :padding="false" wire:key="sg-{{ $s['key'] }}">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-start gap-3">
                        <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white', 'bg-brand-600' => $s['to'], 'bg-slate-400' => ! $s['to']])>
                            <x-dynamic-component :component="$s['to'] ? 'heroicon-s-arrows-right-left' : 'heroicon-s-no-symbol'" class="h-5 w-5" />
                        </span>
                        <div>
                            <div class="text-[15px] font-bold text-navy-900">{{ $s['message'] }}</div>
                            <div class="text-xs text-slate-500">{{ $s['impact'] }}</div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <x-ui.badge color="danger" size="xs">{{ $s['from']['code'] }} · {{ $s['from']['load'] }}/{{ $s['from']['capacity'] }} ({{ $s['from']['util'] }}%)</x-ui.badge>
                                @if ($s['to'])<x-ui.badge color="success" size="xs">{{ $s['to']['code'] }} · {{ $s['to']['load'] }}/{{ $s['to']['capacity'] }} ({{ $s['to']['util'] }}%)</x-ui.badge>@endif
                            </div>
                        </div>
                    </div>
                    @if ($s['to'])
                        <div class="flex shrink-0 gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="selectAll('{{ $s['key'] }}', {{ json_encode(collect($s['clients'])->pluck('id')) }})">Todos</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="clearSelection('{{ $s['key'] }}')">Ninguno</x-ui.button>
                            <x-ui.button size="sm" icon="arrow-right" wire:click="confirm('{{ $s['key'] }}')">Migrar seleccionados ({{ count($selected[$s['key']] ?? []) }})</x-ui.button>
                        </div>
                    @endif
                </div>
                @if ($s['clients'])
                    <x-ui.table>
                        <x-slot:head><th class="w-8"></th><th>Cliente</th><th>Plan</th><th>Estado</th><th class="text-right">Distancia actual</th><th class="text-right">Distancia a {{ $s['to']['code'] }}</th></x-slot:head>
                        @foreach ($s['clients'] as $c)
                            <tr wire:key="sg-{{ $s['key'] }}-{{ $c['id'] }}">
                                <td><input type="checkbox" value="{{ $c['id'] }}" wire:model.live="selected.{{ $s['key'] }}" class="h-4 w-4 rounded border-slate-300 text-brand-600"></td>
                                <td><a class="font-semibold text-slate-800 hover:text-brand-600" href="{{ route('clients.show', $c['id']) }}" wire:navigate>{{ $c['name'] }}</a> <span class="text-xs text-slate-500">#{{ $c['code'] }}</span></td>
                                <td class="text-xs">{{ $c['plan'] }}</td>
                                <td><x-ui.status catalog="client_status" :value="$c['status']" size="xs" /></td>
                                <td class="text-right text-xs">{{ $c['current'] !== null ? $c['current'].' km' : '—' }}</td>
                                <td class="text-right text-xs font-semibold {{ $c['current'] !== null && $c['distance'] <= $c['current'] ? 'text-success-600' : 'text-slate-700' }}">{{ $c['distance'] }} km</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty icon="check-badge" title="Sin repetidores saturados" description="Todos los repetidores operan por debajo de su umbral de alerta." /></x-ui.card>
        @endforelse
    </div>

    <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-2">
        <x-ui.card title="Utilización por zona" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Zona</th><th class="text-right">Repetidores</th><th class="min-w-[180px]">Utilización</th><th class="text-right">Saturados</th></x-slot:head>
                @foreach ($a['zones'] as $z)
                    <tr>
                        <td class="font-medium text-slate-800">{{ $z['zone'] }}</td>
                        <td class="text-right text-sm">{{ $z['repeaters'] }}</td>
                        <td><div class="mb-1 text-xs">{{ $z['load'] }} / {{ $z['capacity'] }} · {{ $z['util'] }}%</div><x-ui.progress :value="$z['util']" /></td>
                        <td class="text-right">@if ($z['saturated'])<x-ui.badge color="danger" size="xs">{{ $z['saturated'] }}</x-ui.badge>@else<span class="text-xs text-slate-400">0</span>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
        <x-ui.card title="Sectores" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Sector</th><th>Torre</th><th>Repetidor</th><th class="min-w-[160px]">Utilización</th></x-slot:head>
                @forelse ($a['sectors'] as $s)
                    <tr @class(['bg-warning-50/60' => $s['high']])>
                        <td class="text-sm font-medium text-slate-800">{{ $s['name'] }}<div class="text-xs text-slate-500">{{ $s['azimuth'] }}° · {{ $s['frequency'] }}</div></td>
                        <td class="text-xs">{{ $s['tower'] }}</td>
                        <td class="text-xs">{{ $s['repeater'] }}</td>
                        <td><x-ui.progress :value="$s['util']" label /></td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="4" icon="signal" title="Sin sectores asociados a repetidores" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>

    <x-ui.card :padding="false">
        <x-slot:title>Clientes mal distribuidos</x-slot:title>
        <x-slot:actions>
            <x-ui.button size="sm" icon="arrow-right" wire:click="confirm('misplaced')">Migrar seleccionados ({{ count(array_filter($misplacedSelected)) }})</x-ui.button>
        </x-slot:actions>
        <x-ui.table>
            <x-slot:head><th class="w-8"></th><th>Cliente</th><th>Motivo</th><th>Actual</th><th>Sugerido</th><th class="text-right">Ahorro de distancia</th></x-slot:head>
            @forelse (array_slice($a['misplaced'], 0, 100) as $c)
                <tr wire:key="mp{{ $c['id'] }}">
                    <td><input type="checkbox" wire:model.live="misplacedSelected.{{ $c['id'] }}" class="h-4 w-4 rounded border-slate-300 text-brand-600"></td>
                    <td><a class="font-semibold text-slate-800 hover:text-brand-600" href="{{ route('clients.show', $c['id']) }}" wire:navigate>{{ $c['name'] }}</a> <span class="text-xs text-slate-500">#{{ $c['code'] }}</span></td>
                    <td class="text-xs text-slate-600">{{ $c['reason'] }}</td>
                    <td class="text-xs">{{ $c['from'] }} <span class="text-slate-400">({{ $c['current'] }} km)</span></td>
                    <td class="text-xs font-semibold text-success-700">{{ $c['to'] }} <span class="font-normal text-slate-400">({{ $c['distance'] }} km)</span></td>
                    <td class="text-right text-xs font-semibold">{{ $c['saving'] }} km</td>
                </tr>
            @empty
                <x-ui.empty :colspan="6" icon="check-circle" title="Sin clientes mal distribuidos" description="Cada cliente está asignado a un repetidor adecuado según cobertura y capacidad." />
            @endforelse
        </x-ui.table>
        @if (count($a['misplaced']) > 100)<div class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">Mostrando 100 de {{ count($a['misplaced']) }}.</div>@endif
    </x-ui.card>

    <x-ui.modal wire:model="showConfirm" title="Confirmar migración" size="md">
        <p class="text-sm text-slate-600">
            Se migrarán <b>{{ $confirmKey === 'misplaced' ? count(array_filter($misplacedSelected)) : count($selected[$confirmKey] ?? []) }}</b> clientes al repetidor sugerido.
            Se registrará un evento de servicio y la auditoría de cada cambio.
        </p>
        <p class="mt-2 text-xs text-slate-500">{{ $applyNetwork ? 'Si el MikroTik cambia, el usuario se creará en el nuevo equipo y se retirará del anterior.' : 'Solo se actualizará la asignación administrativa (sin cambios en MikroTik).' }}</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="apply">Migrar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
