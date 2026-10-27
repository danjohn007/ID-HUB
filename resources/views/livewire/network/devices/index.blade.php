<div>
    <x-ui.page-header title="Equipos de red" subtitle="MikroTik, antenas, CPE, access points, routers, switches, UPS y fuentes">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('network.manage')
            <x-ui.button icon="plus" wire:click="newDevice('{{ $filterType && $filterType !== 'mikrotik' ? $filterType : 'antenna' }}')">Nuevo equipo</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterType', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterType === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterType !== ''])>
            Todos <span class="ml-1 opacity-70">{{ number_format($counts->sum()) }}</span>
        </button>
        @foreach (config('wisp.device_types') as $key => $label)
            <button wire:click="$set('filterType', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterType === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterType !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ number_format($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="filter-bar">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Nombre, IP, MAC, modelo, serie..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterStatus" placeholder="Todos los estados"
                         :options="['online' => 'Operativo ('.($status['online'] ?? 0).')', 'warning' => 'Advertencia ('.($status['warning'] ?? 0).')', 'offline' => 'Fallo / sin comunicación ('.(($status['offline'] ?? 0) + ($status['unknown'] ?? 0)).')', 'maintenance' => 'Mantenimiento', 'retired' => 'Retirado', 'in_stock' => 'En almacén']" />
            <x-ui.select wire:model.live="filterRepeater" :options="$repeaterFilter" placeholder="Todos los repetidores" />
            <div class="flex justify-end">
                @if ($search || $filterType || $filterStatus || $filterRepeater)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar</x-ui.button>
                @endif
            </div>
        </div>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th sort="name" :sort-by="$sortBy" :sort-dir="$sortDir">Equipo</x-ui.th>
                <x-ui.th sort="type" :sort-by="$sortBy" :sort-dir="$sortDir">Tipo</x-ui.th>
                <x-ui.th sort="ip" :sort-by="$sortBy" :sort-dir="$sortDir">IP / MAC</x-ui.th>
                <th>Ubicación</th>
                <x-ui.th sort="signal_dbm" :sort-by="$sortBy" :sort-dir="$sortDir">Métricas</x-ui.th>
                <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                <x-ui.th sort="last_seen_at" :sort-by="$sortBy" :sort-dir="$sortDir">Último contacto</x-ui.th>
                <th></th>
            </x-slot:head>
            @forelse ($devices as $d)
                @php $url = $d->type === 'mikrotik' ? route('network.mikrotik.show', $d) : route('network.devices.show', $d); @endphp
                <tr wire:key="d{{ $d->id }}">
                    <td>
                        <a href="{{ $url }}" wire:navigate class="block">
                            <span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $d->name }}</span>
                            <span class="block text-xs text-slate-500">{{ trim($d->manufacturer.' '.$d->model) ?: '—' }}</span>
                        </a>
                    </td>
                    <td><x-ui.badge :color="['mikrotik' => 'navy', 'ap' => 'purple', 'cpe' => 'brand', 'antenna' => 'teal'][$d->type] ?? 'slate'" size="xs">{{ config('wisp.device_types.'.$d->type, $d->type) }}</x-ui.badge></td>
                    <td class="text-xs"><div class="text-slate-700">{{ $d->ip ?? '—' }}</div><div class="font-mono text-2xs text-slate-500">{{ $d->mac }}</div></td>
                    <td class="text-xs">
                        @if ($d->client)<a class="link block" href="{{ route('clients.show', $d->client) }}" wire:navigate>#{{ $d->client->code }} {{ \Illuminate\Support\Str::limit($d->client->full_name, 22) }}</a>@endif
                        <span class="text-slate-500">{{ collect([$d->repeater?->code, $d->tower?->code])->filter()->implode(' · ') ?: ($d->location ?? '') }}</span>
                    </td>
                    <td class="text-xs text-slate-600">
                        @if ($d->signal_dbm !== null)<div class="{{ $d->signal_dbm < tenant()->setting('network.signal_min_dbm', -75) ? 'font-semibold text-danger-600' : '' }}">{{ (float) $d->signal_dbm }} dBm</div>@endif
                        @if ($d->cpu !== null)<div>CPU {{ round($d->cpu) }}% · Mem {{ round($d->memory) }}%</div>@endif
                        @if ($d->latency_ms !== null)<div>{{ (float) $d->latency_ms }} ms</div>@endif
                    </td>
                    <td><x-ui.status catalog="device_status" :value="$d->status" size="xs" /></td>
                    <td class="text-xs text-slate-500">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                    <td class="text-right whitespace-nowrap">
                        @can('network.manage')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editDevice({{ $d->id }})" title="Editar" />
                            @if ($d->status !== 'retired')
                                <x-ui.button variant="ghost" size="sm" icon="archive-box-x-mark" wire:click="retire({{ $d->id }})" wire:confirm="¿Marcar el equipo como retirado?" title="Marcar retirado" />
                            @endif
                        @endcan
                        <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="$url" title="Ver detalle" />
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="8" icon="server-stack" title="No hay equipos" description="Ajusta los filtros o registra un nuevo equipo." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $devices->links() }}</div>
    </x-ui.card>

    @include('livewire.network.partials.device-form')
</div>
