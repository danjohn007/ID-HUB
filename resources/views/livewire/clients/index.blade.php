<div>
    <x-ui.page-header title="Clientes" subtitle="Administración de clientes, servicio y estado de cuenta">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('clients.manage')
            <x-ui.button :href="route('clients.create')" icon="plus">Nuevo cliente</x-ui.button>
        @endcan
    </x-ui.page-header>

    {{-- Filtro rápido por estado --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterStatus', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== ''])>
            Todos <span class="ml-1 opacity-70">{{ number_format($counts->sum()) }}</span>
        </button>
        @foreach (config('wisp.client_status') as $key => [$label, $color])
            <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ number_format($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="filter-bar">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Nombre, código, teléfono, IP, MAC, PPPoE..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Todas las zonas" />
            <x-ui.select wire:model.live="filterPlan" :options="$plans" placeholder="Todos los planes" />
            <x-ui.select wire:model.live="filterRepeater" :options="$repeaters" placeholder="Todos los repetidores" />
            <div class="flex gap-2">
                <x-ui.select wire:model.live="filterRisk" :options="collect(config('wisp.risk_levels'))->map(fn ($r) => 'Riesgo '.$r[0])" placeholder="Riesgo" class="flex-1" />
                @if ($search || $filterZone || $filterPlan || $filterRepeater || $filterRisk || $filterStatus)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                @endif
            </div>
        </div>

        <x-ui.table stack>
            <x-slot:head>
                <x-ui.th sort="code" :sort-by="$sortBy" :sort-dir="$sortDir">Cliente</x-ui.th>
                <th>Contacto</th>
                <th>Plan</th>
                <th class="hidden 2xl:table-cell">Zona / Repetidor</th>
                <th>Estado</th>
                <x-ui.th sort="balance" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Saldo</x-ui.th>
                <x-ui.th sort="next_due_date" :sort-by="$sortBy" :sort-dir="$sortDir">Próximo pago</x-ui.th>
                <x-ui.th sort="risk_score" :sort-by="$sortBy" :sort-dir="$sortDir">Riesgo</x-ui.th>
                <th></th>
            </x-slot:head>
            @forelse ($clients as $c)
                <tr wire:key="c{{ $c->id }}">
                    <td>
                        <a href="{{ route('clients.show', $c) }}" wire:navigate class="flex items-center gap-3">
                            <span class="relative">
                                <x-ui.avatar :name="$c->full_name" />
                                <span @class(['absolute -right-0.5 -bottom-0.5 h-3 w-3 rounded-full ring-2 ring-white', 'bg-success-500' => $c->online, 'bg-slate-300' => ! $c->online])></span>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-semibold whitespace-nowrap text-slate-800 hover:text-brand-600">{{ $c->full_name }}</span>
                                <span class="block text-xs text-slate-500">#{{ $c->code }} · {{ $c->pppoe_user ?: $c->ip_address }}</span>
                            </span>
                        </a>
                    </td>
                    <td class="text-xs" data-label="Contacto">
                        <div class="text-slate-700">{{ $c->phone }}</div>
                        <div class="max-w-[200px] truncate text-slate-500">{{ $c->address }}</div>
                    </td>
                    <td data-label="Plan">
                        @if ($c->plan)
                            <span class="inline-flex items-center gap-1.5 text-sm font-medium"><span class="h-2 w-2 rounded-full" style="background: {{ $c->plan->color }}"></span>{{ $c->plan->name }}</span>
                            <div class="text-xs text-slate-500">{{ $c->plan->speedLabel() }}</div>
                        @endif
                    </td>
                    <td class="hidden text-xs 2xl:table-cell" data-label="Zona"><div class="text-slate-700">{{ $c->zone?->name ?? '—' }}</div><div class="text-slate-500">{{ $c->repeater?->code }}</div></td>
                    <td data-label="Estado"><x-ui.status catalog="client_status" :value="$c->service_status" /></td>
                    <td class="text-right font-semibold {{ $c->balance > 0 ? 'text-danger-600' : 'text-slate-700' }}" data-label="Saldo">{{ money($c->balance) }}</td>
                    <td class="text-xs text-slate-600" data-label="Próximo pago">{{ $c->next_due_date?->translatedFormat('d M Y') ?? '—' }}</td>
                    <td data-label="Riesgo"><x-ui.status catalog="risk_levels" :value="$c->risk_level" :dot="false" size="xs" /></td>
                    <td class="text-right max-md:hidden">
                        <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('clients.show', $c)" />
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="9" icon="users" title="No hay clientes" description="Ajusta los filtros o registra un nuevo cliente." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $clients->links() }}</div>
    </x-ui.card>
</div>
