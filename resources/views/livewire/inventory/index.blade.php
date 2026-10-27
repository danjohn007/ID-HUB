<div>
    <x-ui.page-header title="Inventario" subtitle="Existencias, movimientos de almacén y equipos instalados en campo">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('inventory.manage')
            <x-ui.button variant="secondary" icon="arrows-right-left" wire:click="openMovement">Registrar movimiento</x-ui.button>
            <x-ui.button icon="plus" wire:click="newItem">Nuevo artículo</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <x-ui.stat label="Valuación del inventario" :value="money($kpi['value'], false)" icon="banknotes" color="navy" :sub="$kpi['items'].' artículos activos'" />
        <x-ui.stat label="Bajo mínimo" :value="$kpi['low']" icon="arrow-trending-down" color="warning" sub="Requieren reabastecer" :href="route('inventory.index', ['existencia' => 'low'])" />
        <x-ui.stat label="Sin existencia" :value="$kpi['out']" icon="x-circle" color="danger" sub="Agotados" :href="route('inventory.index', ['existencia' => 'out'])" />
        <x-ui.stat label="Movimientos del mes" :value="number_format($kpi['moves'])" icon="arrows-right-left" color="brand" :sub="$inv->qty($kpi['installed']).' piezas instaladas'" />
        <x-ui.stat label="Equipos en campo" :value="number_format(\App\Models\NetworkDevice::whereIn('type', ['cpe', 'antenna', 'router'])->count())" icon="wifi" color="teal" sub="CPE, antenas y routers" class="col-span-2 md:col-span-1" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="['articulos' => 'Artículos y existencias', 'movimientos' => 'Movimientos', 'equipos' => 'Equipos instalados']" />

    {{-- ============================== ARTÍCULOS ============================== --}}
    @if ($tab === 'articulos')
        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('filterCategory', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterCategory === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterCategory !== ''])>Todas</button>
            @foreach (config('wisp.inventory_categories') as $key => $label)
                <button wire:click="$set('filterCategory', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterCategory === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterCategory !== $key])>
                    {{ $label }} <span class="ml-1 opacity-70">{{ $byCategory[$key]->n ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        <x-ui.card :padding="false">
            <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="SKU, nombre, marca, modelo, ubicación..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterStock" :options="['low' => 'Bajo mínimo', 'out' => 'Sin existencia', 'ok' => 'Suficiente', 'over' => 'Sobre máximo']" placeholder="Estado de existencia" />
                <div class="flex items-center"><x-ui.toggle wire:model.live="showInactive" label="Incluir inactivos" /></div>
            </div>
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th sort="sku" :sort-by="$sortBy" :sort-dir="$sortDir">SKU</x-ui.th>
                    <x-ui.th sort="name" :sort-by="$sortBy" :sort-dir="$sortDir">Artículo</x-ui.th>
                    <x-ui.th sort="category" :sort-by="$sortBy" :sort-dir="$sortDir">Categoría</x-ui.th>
                    <th>Ubicación</th>
                    <x-ui.th sort="stock" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Existencia</x-ui.th>
                    <th class="w-40">Mín / Máx</th>
                    <th>Estado</th>
                    <x-ui.th sort="unit_cost" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Costo</x-ui.th>
                    <th class="text-right">Valor</th>
                    <th></th>
                </x-slot:head>
                @forelse ($items as $i)
                    @php
                        $state = $i->stockState();
                        $cap = (float) ($i->max_stock ?: max((float) $i->min_stock * 2, 1));
                    @endphp
                    <tr wire:key="i{{ $i->id }}" @class(['bg-danger-50/50' => $state === 'danger' && $i->active, 'bg-warning-50/50' => $state === 'warning' && $i->active, 'opacity-50' => ! $i->active])>
                        <td class="font-mono text-xs font-semibold text-slate-600">{{ $i->sku }}</td>
                        <td>
                            <button wire:click="openHistory({{ $i->id }})" class="text-left font-semibold text-slate-800 hover:text-brand-600">{{ $i->name }}</button>
                            <div class="text-xs text-slate-500">{{ trim($i->brand.' '.$i->model) ?: '—' }}{{ $i->serialized ? ' · Serializado' : '' }}</div>
                        </td>
                        <td class="text-xs">{{ config('wisp.inventory_categories.'.$i->category, $i->category) }}</td>
                        <td class="text-xs text-slate-600">{{ $i->location ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap"><span class="text-base font-bold {{ $state === 'danger' ? 'text-danger-600' : ($state === 'warning' ? 'text-warning-700' : 'text-navy-900') }}">{{ $inv->qty($i->stock) }}</span> <span class="text-xs text-slate-500">{{ $i->unit }}</span></td>
                        <td>
                            <x-ui.progress :value="min(100, (float) $i->stock / $cap * 100)" :color="['danger' => 'danger', 'warning' => 'warning', 'info' => 'purple', 'success' => 'success'][$state]" height="h-1.5" />
                            <div class="mt-0.5 text-2xs text-slate-500">Mín {{ $inv->qty($i->min_stock) }} · Máx {{ $i->max_stock !== null ? $inv->qty($i->max_stock) : '—' }}</div>
                        </td>
                        <td><x-ui.badge :color="$state === 'info' ? 'purple' : $state" dot size="xs">{{ \App\Livewire\Inventory\Index::stockLabel($i) }}</x-ui.badge></td>
                        <td class="text-right text-xs">{{ money($i->unit_cost) }}</td>
                        <td class="text-right font-semibold text-slate-800">{{ money((float) $i->stock * (float) $i->unit_cost) }}</td>
                        <td class="text-right whitespace-nowrap">
                            @can('inventory.manage')
                                <button wire:click="openMovement({{ $i->id }}, 'entrada')" class="rounded-md p-1 text-success-600 hover:bg-success-50" title="Entrada"><x-heroicon-o-arrow-down-on-square class="h-4 w-4" /></button>
                                <button wire:click="openMovement({{ $i->id }}, 'salida')" class="rounded-md p-1 text-warning-600 hover:bg-warning-50" title="Salida"><x-heroicon-o-arrow-up-on-square class="h-4 w-4" /></button>
                            @endcan
                            <x-ui.dropdown>
                                <x-slot:trigger><button class="rounded-md p-1 text-slate-500 hover:bg-slate-100"><x-heroicon-o-ellipsis-vertical class="h-4 w-4" /></button></x-slot:trigger>
                                <x-ui.dropdown-item icon="clock" wire:click="openHistory({{ $i->id }})">Historial de movimientos</x-ui.dropdown-item>
                                @can('inventory.manage')
                                    <x-ui.dropdown-item icon="wrench" wire:click="openMovement({{ $i->id }}, 'instalacion')">Instalación</x-ui.dropdown-item>
                                    <x-ui.dropdown-item icon="arrow-uturn-left" wire:click="openMovement({{ $i->id }}, 'devolucion')">Devolución</x-ui.dropdown-item>
                                    <x-ui.dropdown-item icon="adjustments-horizontal" wire:click="openMovement({{ $i->id }}, 'ajuste')">Ajuste (conteo físico)</x-ui.dropdown-item>
                                    <x-ui.dropdown-item icon="pencil-square" wire:click="editItem({{ $i->id }})">Editar</x-ui.dropdown-item>
                                    <x-ui.dropdown-item :icon="$i->active ? 'eye-slash' : 'eye'" wire:click="toggleActive({{ $i->id }})">{{ $i->active ? 'Desactivar' : 'Reactivar' }}</x-ui.dropdown-item>
                                @endcan
                            </x-ui.dropdown>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="10" icon="archive-box" title="Sin artículos" description="Ajusta los filtros o registra un artículo." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $items->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ============================== MOVIMIENTOS ============================== --}}
    @if ($tab === 'movimientos')
        <x-ui.card :padding="false">
            <div class="filter-bar">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Artículo, referencia, serie..." class="col-span-2" />
                <x-ui.select wire:model.live="filterType" :options="config('wisp.inventory_movement_types')" placeholder="Tipo" />
                <x-ui.select wire:model.live="filterItem" :options="$itemOptions" placeholder="Artículo" />
                <x-ui.input type="date" wire:model.live="filterFrom" />
                <x-ui.input type="date" wire:model.live="filterTo" />
            </div>
            <x-ui.table>
                <x-slot:head><th>Fecha</th><th>Artículo</th><th>Tipo</th><th class="text-right">Cantidad</th><th class="text-right">Existencia</th><th>Referencia</th><th>Cliente / ticket</th><th>Usuario</th></x-slot:head>
                @forelse ($movements as $m)
                    <tr wire:key="m{{ $m->id }}">
                        <td class="text-xs whitespace-nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                        <td><button wire:click="openHistory({{ $m->inventory_item_id }})" class="text-left text-sm font-medium text-slate-800 hover:text-brand-600">{{ $m->item?->name }}</button><div class="font-mono text-2xs text-slate-400">{{ $m->item?->sku }}{{ $m->serial ? ' · S/N '.$m->serial : '' }}</div></td>
                        <td><x-ui.status catalog="inventory_movement_types" :value="$m->type" size="xs" /></td>
                        <td class="text-right font-semibold {{ $m->quantity < 0 ? 'text-danger-600' : 'text-success-700' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $inv->qty($m->quantity) }} <span class="text-xs font-normal text-slate-400">{{ $m->item?->unit }}</span></td>
                        <td class="text-right text-sm text-slate-700">{{ $m->stock_after !== null ? $inv->qty($m->stock_after) : '—' }}</td>
                        <td class="max-w-[200px] text-xs"><div class="truncate">{{ $m->reference ?? '—' }}</div>@if ($m->notes)<div class="truncate text-slate-400">{{ $m->notes }}</div>@endif</td>
                        <td class="text-xs">
                            @if ($m->client)<a href="{{ route('clients.show', $m->client) }}" wire:navigate class="link">{{ $m->client->full_name }}</a>@endif
                            @if ($m->ticket)<div><a href="{{ route('tickets.show', $m->ticket) }}" wire:navigate class="link">{{ $m->ticket->folio }}</a></div>@endif
                            @if ($m->installationOrder)<div>{{ $m->installationOrder->folio }}</div>@endif
                            @if (! $m->client && ! $m->ticket && ! $m->installationOrder)<span class="text-slate-400">—</span>@endif
                        </td>
                        <td class="text-xs text-slate-600">{{ $m->user?->name ?? 'Sistema' }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="arrows-right-left" title="Sin movimientos en el periodo" />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $movements->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ============================== EQUIPOS INSTALADOS ============================== --}}
    @if ($tab === 'equipos')
        <x-ui.card :padding="false">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Equipo, MAC, IP, serie o cliente..." class="md:w-96" />
                <div class="flex gap-2 overflow-x-auto">
                    @foreach (['' => 'Todos', 'cpe' => 'CPE', 'antenna' => 'Antenas', 'router' => 'Routers'] as $k => $label)
                        <button wire:click="$set('filterDeviceType', '{{ $k }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterDeviceType === $k, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterDeviceType !== $k])>
                            {{ $label }} <span class="ml-1 opacity-70">{{ $k ? ($deviceCounts[$k] ?? 0) : $deviceCounts->sum() }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500 md:ml-auto">Cliente → CPE → Repetidor → Torre</p>
            </div>
            <x-ui.table>
                <x-slot:head><th>Equipo</th><th>MAC / IP</th><th>Ubicación (cadena)</th><th>Estado</th><th>Instalado</th><th></th></x-slot:head>
                @forelse ($devices as $d)
                    @php $rep = $d->repeater ?? $d->client?->repeater; $tower = $rep?->tower ?? $d->tower; @endphp
                    <tr wire:key="d{{ $d->id }}">
                        <td>
                            <div class="font-semibold text-slate-800">{{ $d->name }}</div>
                            <div class="text-xs text-slate-500">{{ config('wisp.device_types.'.$d->type, $d->type) }} · {{ trim($d->manufacturer.' '.$d->model) }}{{ $d->serial ? ' · S/N '.$d->serial : '' }}</div>
                        </td>
                        <td class="font-mono text-xs text-slate-600">{{ $d->mac ?? '—' }}<div>{{ $d->ip }}</div></td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1 text-xs">
                                @if ($d->client)
                                    <a href="{{ route('clients.show', $d->client) }}" wire:navigate class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-1.5 py-0.5 font-semibold text-brand-700 hover:bg-brand-100"><x-heroicon-m-home class="h-3 w-3" />{{ $d->client->full_name }}</a>
                                @else
                                    <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-500">Infraestructura</span>
                                @endif
                                <x-heroicon-m-chevron-right class="h-3 w-3 text-slate-400" />
                                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-semibold text-slate-700">{{ strtoupper($d->type) === 'ANTENNA' ? 'Antena' : strtoupper($d->type) }}</span>
                                <x-heroicon-m-chevron-right class="h-3 w-3 text-slate-400" />
                                <span class="rounded-md bg-purple-50 px-1.5 py-0.5 font-semibold text-purple-700">{{ $rep?->code ?? 'Sin repetidor' }}</span>
                                <x-heroicon-m-chevron-right class="h-3 w-3 text-slate-400" />
                                <span class="rounded-md bg-warning-50 px-1.5 py-0.5 font-semibold text-warning-700">{{ $tower?->name ?? 'Sin torre' }}</span>
                            </div>
                        </td>
                        <td><x-ui.status catalog="device_status" :value="$d->status" size="xs" /></td>
                        <td class="text-xs text-slate-600">{{ $d->installed_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-right">
                            @if (Route::has('network.devices.show'))<x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('network.devices.show', $d)" />@endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="6" icon="wifi" title="Sin equipos instalados" />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $devices->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ============================== MODALES ============================== --}}
    <x-ui.modal wire:model="showItem" :title="$itemId ? 'Editar artículo' : 'Nuevo artículo'" size="2xl">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <x-ui.input label="SKU" wire:model="item.sku" filter="code" maxlength="40" class="sm:col-span-2" required />
            <x-ui.input label="Nombre" wire:model="item.name" filter="text" maxlength="190" class="sm:col-span-4" required />
            <x-ui.select label="Categoría" wire:model="item.category" :options="config('wisp.inventory_categories')" class="sm:col-span-2" />
            <x-ui.input label="Marca" wire:model="item.brand" filter="text" maxlength="120" class="sm:col-span-2" />
            <x-ui.input label="Modelo" wire:model="item.model" filter="text" maxlength="120" class="sm:col-span-2" />
            <x-ui.select label="Unidad" wire:model="item.unit" :options="['pza' => 'Pieza', 'm' => 'Metro', 'rollo' => 'Rollo', 'caja' => 'Caja', 'kit' => 'Kit', 'par' => 'Par']" class="sm:col-span-2" />
            <x-ui.input label="Costo unitario" type="number" step="0.01" min="0" max="9999999" wire:model="item.unit_cost" prefix="$" class="sm:col-span-2" />
            <x-ui.input label="Ubicación" wire:model="item.location" filter="text" maxlength="120" placeholder="Almacén, camioneta..." class="sm:col-span-2" />
            @if (! $itemId)
                <x-ui.input label="Existencia inicial" type="number" step="0.01" min="0" max="9999999" wire:model="item.stock" class="sm:col-span-2" hint="Se registra como entrada." />
            @else
                <div class="sm:col-span-2"><label class="form-label">Existencia actual</label><div class="form-input bg-slate-50 text-slate-600">{{ $item['stock'] }} {{ $item['unit'] }}</div><p class="mt-1 text-xs text-slate-500">Cámbiala con movimientos.</p></div>
            @endif
            <x-ui.input label="Mínimo" type="number" step="0.01" min="0" max="9999999" wire:model="item.min_stock" class="sm:col-span-2" hint="Alerta al llegar a este nivel." />
            <x-ui.input label="Máximo" type="number" step="0.01" min="0" max="9999999" wire:model="item.max_stock" class="sm:col-span-2" />
            <div class="space-y-3 sm:col-span-6">
                <x-ui.toggle wire:model="item.serialized" label="Artículo serializado" description="Pide número de serie en cada movimiento (antenas, CPE, routers)." />
                <x-ui.toggle wire:model="item.active" label="Activo" />
            </div>
        </div>
        <x-slot:footer>
            @if ($itemId)
                <x-ui.button variant="ghost" icon="trash" class="mr-auto text-danger-600" wire:click="deleteItem({{ $itemId }})" wire:confirm="¿Eliminar este artículo?">Eliminar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveItem">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showMovement" title="Registrar movimiento" size="xl">
        <div class="space-y-4">
            <div class="grid grid-cols-5 gap-2">
                @foreach (config('wisp.inventory_movement_types') as $k => [$label, $color])
                    <button type="button" wire:click="$set('movement.type', '{{ $k }}')" @class(['rounded-lg border-2 px-2 py-2 text-xs font-semibold transition', 'border-brand-600 bg-brand-50 text-brand-700' => $movement['type'] === $k, 'border-slate-200 text-slate-600 hover:border-slate-300' => $movement['type'] !== $k])>{{ $label }}</button>
                @endforeach
            </div>
            <x-ui.select label="Artículo" wire:model.live="movement.item_id" :options="$itemOptions" placeholder="Selecciona..." required />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($movement['type'] === 'ajuste')
                    <x-ui.input label="Existencia física contada" type="number" step="0.01" min="0" max="9999999" wire:model="movement.counted" required hint="Se registra la diferencia contra el sistema." />
                @else
                    <x-ui.input label="Cantidad" type="number" step="0.01" min="0.01" max="9999999" wire:model="movement.quantity" required :hint="in_array($movement['type'], ['entrada', 'devolucion']) ? 'Suma a la existencia' : 'Se descuenta de la existencia'" />
                @endif
                @if ($movement['type'] === 'entrada')
                    <x-ui.input label="Costo unitario de compra" type="number" step="0.01" min="0" max="9999999" wire:model="movement.unit_cost" prefix="$" hint="Actualiza el costo promedio." />
                @else
                    <x-ui.input label="Número de serie" wire:model="movement.serial" filter="code" maxlength="120" />
                @endif
                <x-ui.input label="Referencia" wire:model="movement.reference" filter="text" maxlength="120" placeholder="Factura, orden de compra, vale..." />
                <x-ui.input label="Ticket (folio)" wire:model="movement.ticket_folio" filter="upper" maxlength="30" placeholder="T-001258" />
            </div>
            @if (in_array($movement['type'], ['salida', 'instalacion', 'devolucion']))
                <div class="relative">
                    <label class="form-label">Cliente (opcional)</label>
                    <input type="text" wire:model.live.debounce.300ms="clientSearch" placeholder="Buscar cliente..." autocomplete="off" class="form-input {{ $movement['client_id'] ? 'border-success-500 bg-success-50/40' : '' }}">
                    @if ($clientResults->isNotEmpty())
                        <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                            @foreach ($clientResults as $c)
                                <button type="button" wire:click="selectClient({{ $c->id }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50"><b>{{ $c->full_name }}</b> <span class="text-slate-400">#{{ $c->code }}</span><span class="block truncate text-xs text-slate-500">{{ $c->address }}</span></button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
            <x-ui.input label="Notas" wire:model="movement.notes" maxlength="190" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveMovement">Registrar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showHistory" :title="isset($historyItem) && $historyItem ? 'Historial · '.$historyItem->name : 'Historial'" size="4xl">
        @if (isset($historyItem) && $historyItem)
            <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <x-ui.stat variant="soft" label="Existencia" :value="$inv->qty($historyItem->stock).' '.$historyItem->unit" icon="cube" :color="$historyItem->stockState() === 'success' ? 'success' : ($historyItem->stockState() === 'info' ? 'purple' : $historyItem->stockState())" />
                <x-ui.stat variant="soft" label="Mínimo / Máximo" :value="$inv->qty($historyItem->min_stock).' / '.($historyItem->max_stock !== null ? $inv->qty($historyItem->max_stock) : '—')" icon="adjustments-horizontal" color="slate" />
                <x-ui.stat variant="soft" label="Costo promedio" :value="money($historyItem->unit_cost)" icon="tag" color="brand" />
                <x-ui.stat variant="soft" label="Valor" :value="money((float) $historyItem->stock * (float) $historyItem->unit_cost)" icon="banknotes" color="navy" />
            </div>
            <x-ui.table class="max-h-[50vh]">
                <x-slot:head><th>Fecha</th><th>Tipo</th><th class="text-right">Cantidad</th><th class="text-right">Existencia</th><th>Referencia</th><th>Cliente / ticket</th><th>Usuario</th></x-slot:head>
                @forelse ($history as $m)
                    <tr>
                        <td class="text-xs whitespace-nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                        <td><x-ui.status catalog="inventory_movement_types" :value="$m->type" size="xs" /></td>
                        <td class="text-right font-semibold {{ $m->quantity < 0 ? 'text-danger-600' : 'text-success-700' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $inv->qty($m->quantity) }}</td>
                        <td class="text-right">{{ $m->stock_after !== null ? $inv->qty($m->stock_after) : '—' }}</td>
                        <td class="text-xs">{{ $m->reference }}{{ $m->serial ? ' · S/N '.$m->serial : '' }}</td>
                        <td class="text-xs">{{ $m->client?->full_name }}{{ $m->ticket ? ' · '.$m->ticket->folio : '' }}</td>
                        <td class="text-xs">{{ $m->user?->name ?? 'Sistema' }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="7" title="Sin movimientos" />
                @endforelse
            </x-ui.table>
        @endif
        <x-slot:footer>
            @can('inventory.manage')
                @if (isset($historyItem) && $historyItem)
                    <x-ui.button variant="secondary" icon="arrows-right-left" wire:click="openMovement({{ $historyItem->id }})">Registrar movimiento</x-ui.button>
                @endif
            @endcan
            <x-ui.button variant="secondary" @click="open = false">Cerrar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
