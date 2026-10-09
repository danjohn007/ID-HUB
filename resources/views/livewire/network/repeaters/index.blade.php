<div>
    <x-ui.page-header title="Repetidores" subtitle="Capacidad, clientes conectados y estado de cada repetidor">
        <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 shadow-xs">
            <button wire:click="$set('view', 'lista')" @class(['flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-semibold', 'bg-navy-900 text-white' => $view === 'lista', 'text-slate-600' => $view !== 'lista'])><x-heroicon-m-list-bullet class="h-4 w-4" />Lista</button>
            <button wire:click="$set('view', 'mapa')" @class(['flex items-center gap-1 rounded-md px-2.5 py-1.5 text-xs font-semibold', 'bg-navy-900 text-white' => $view === 'mapa', 'text-slate-600' => $view !== 'mapa'])><x-heroicon-m-map class="h-4 w-4" />Mapa</button>
        </div>
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('network.manage')
            <x-ui.button icon="plus" wire:click="newRepeater">Nuevo repetidor</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat variant="soft" label="Repetidores" :value="$summary['total']" icon="wifi" color="navy" :sub="$summary['online'].' operativos'" />
        <x-ui.stat variant="soft" label="Fuera de servicio" :value="$summary['offline']" icon="exclamation-triangle" :color="$summary['offline'] ? 'danger' : 'success'" sub="Con falla activa" />
        <x-ui.stat variant="soft" label="Saturados" :value="$summary['saturated']" icon="chart-bar" :color="$summary['saturated'] ? 'warning' : 'success'" sub="En o sobre su umbral" />
        <x-ui.stat variant="soft" label="Capacidad utilizada" :value="pct($summary['capacity'] ? $summary['clients'] / $summary['capacity'] * 100 : 0, 0)" icon="users" color="brand"
                   :sub="number_format($summary['clients']).' / '.number_format($summary['capacity']).' clientes · '.number_format($summary['connected']).' conectados'" />
    </div>

    @if ($view === 'mapa')
        <x-ui.card :padding="false">
            <x-ui.map id="repeaters-map" :markers="$map['markers']" :circles="$map['circles']" height="560px" fit class="rounded-xl border-0" />
        </x-ui.card>
    @else
        <x-ui.card :padding="false">
            <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-5">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="ID, nombre o ubicación..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterStatus" :options="['online' => 'Operativo', 'warning' => 'Advertencia', 'offline' => 'Fuera de servicio', 'maintenance' => 'Mantenimiento', 'saturated' => 'Saturados']" placeholder="Todos los estados" />
                <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Todas las zonas" />
                <x-ui.select wire:model.live="filterTower" :options="$towerFilter" placeholder="Todas las torres" />
            </div>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th sort="code" :sort-by="$sortBy" :sort-dir="$sortDir">Repetidor</x-ui.th>
                    <th>Torre / Zona</th>
                    <th>MikroTik</th>
                    <x-ui.th sort="active_clients_count" :sort-by="$sortBy" :sort-dir="$sortDir" class="min-w-[220px]">Capacidad</x-ui.th>
                    <x-ui.th sort="online_clients_count" :sort-by="$sortBy" :sort-dir="$sortDir">Conectados</x-ui.th>
                    <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                    <th></th>
                </x-slot:head>
                @forelse ($repeaters as $r)
                    @php $util = $r->utilization(); $load = $r->clientLoad(); @endphp
                    <tr wire:key="r{{ $r->id }}">
                        <td>
                            <a href="{{ route('network.repeaters.show', $r) }}" wire:navigate class="flex items-center gap-3">
                                <span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', 'bg-danger-50 text-danger-600' => $r->status === 'offline', 'bg-warning-50 text-warning-600' => $r->status === 'warning', 'bg-brand-50 text-brand-600' => ! in_array($r->status, ['offline', 'warning'])])><x-heroicon-o-wifi class="h-5 w-5" /></span>
                                <span><span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $r->code }}</span><span class="block text-xs text-slate-500">{{ $r->name }}</span></span>
                            </a>
                        </td>
                        <td class="text-xs"><div class="text-slate-700">{{ $r->tower?->code ?? '—' }}</div><div class="text-slate-500">{{ $r->zone?->name }}</div></td>
                        <td class="text-xs text-slate-600">{{ $r->router?->name ?? '—' }}</td>
                        <td>
                            <div class="mb-1 text-xs"><span class="font-semibold text-slate-800">{{ $load }} / {{ $r->capacity }} clientes</span> <span class="text-slate-500">· {{ round($util) }}%</span></div>
                            <x-ui.progress :value="$util" :color="$util >= 95 ? 'danger' : ($util >= $r->alert_threshold ? 'warning' : 'success')" />
                        </td>
                        <td class="text-sm"><span class="font-semibold text-slate-800">{{ $r->status === 'offline' ? 0 : $r->online_clients_count }}</span><span class="text-xs text-slate-500"> en línea</span></td>
                        <td>
                            <x-ui.status catalog="device_status" :value="$r->status" size="xs" />
                            @if ($r->status === 'offline' && $r->down_since)<div class="mt-0.5 text-[11px] text-danger-600">desde {{ $r->down_since->diffForHumans() }}</div>@endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @can('network.manage')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editRepeater({{ $r->id }})" title="Editar" />
                            @endcan
                            <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('network.repeaters.show', $r)" />
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" icon="wifi" title="No hay repetidores" description="Registra tu primer repetidor para monitorear su capacidad." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $repeaters->links() }}</div>
        </x-ui.card>
    @endif

    @include('livewire.network.partials.repeater-form')
</div>
