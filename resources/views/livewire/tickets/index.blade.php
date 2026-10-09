<div>
    <x-ui.page-header title="Tickets" subtitle="Soporte técnico, SLA y seguimiento de incidencias">
        <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 shadow-xs">
            <button wire:click="setView('lista')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold transition', 'bg-navy-900 text-white' => $view === 'lista', 'text-slate-600 hover:bg-slate-50' => $view !== 'lista'])>
                <x-heroicon-m-list-bullet class="h-4 w-4" /> Lista
            </button>
            <button wire:click="setView('tablero')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-semibold transition', 'bg-navy-900 text-white' => $view === 'tablero', 'text-slate-600 hover:bg-slate-50' => $view !== 'tablero'])>
                <x-heroicon-m-view-columns class="h-4 w-4" /> Tablero
            </button>
        </div>
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('settings.manage')
            <x-ui.button variant="secondary" icon="clock" :href="route('tickets.sla')">Políticas SLA</x-ui.button>
        @endcan
        @can('tickets.manage')
            <x-ui.button icon="plus" wire:click="$set('showCreate', true)">Nuevo ticket</x-ui.button>
        @endcan
    </x-ui.page-header>

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <x-ui.stat label="Tickets abiertos" :value="number_format($kpi['open'])" icon="ticket" color="brand" :sub="$kpi['unassigned'].' sin técnico asignado'" />
        <x-ui.stat label="Críticos" :value="$kpi['critical']" icon="fire" color="danger" sub="Prioridad crítica abiertos" />
        <x-ui.stat label="Vencidos SLA" :value="$kpi['overdue']" icon="exclamation-triangle" color="warning" :sub="$kpi['risk'].' en riesgo de vencer'" />
        <x-ui.stat label="Resolución prom." :value="\App\Livewire\Tickets\Index::duration($kpi['avg_resolution'])" icon="clock" color="purple" :sub="$kpi['resolved_month'].' resueltos este mes'" />
        <x-ui.stat label="En SLA (mes)" :value="$kpi['sla_pct'] !== null ? pct($kpi['sla_pct']) : '—'" icon="check-badge" :color="$kpi['sla_pct'] === null || $kpi['sla_pct'] >= 90 ? 'success' : ($kpi['sla_pct'] >= 75 ? 'teal' : 'danger')" sub="Tickets resueltos en tiempo" class="col-span-2 md:col-span-1" />
    </div>

    {{-- Estado --}}
    @if ($view === 'lista')
        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            @php
                $openCount = collect(\App\Models\Ticket::OPEN_STATUSES)->sum(fn ($s) => $counts[$s] ?? 0);
                $chips = ['open' => ['Abiertos', $openCount]] + collect(config('wisp.ticket_status'))->map(fn ($s, $k) => [$s[0], $counts[$k] ?? 0])->all() + ['all' => ['Todos', $counts->sum()]];
            @endphp
            @foreach ($chips as $key => [$label, $n])
                <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== $key])>
                    {{ $label }} <span class="ml-1 opacity-70">{{ number_format($n) }}</span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Filtros --}}
    <div class="card mb-4 grid grid-cols-2 gap-3 p-4 md:grid-cols-4 xl:grid-cols-6">
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Folio, asunto o cliente..." class="col-span-2" />
        <x-ui.select wire:model.live="filterPriority" :options="config('wisp.ticket_priorities')" placeholder="Prioridad" />
        <x-ui.select wire:model.live="filterCategory" :options="config('wisp.ticket_categories')" placeholder="Categoría" />
        <x-ui.select wire:model.live="filterTechnician" :options="['none' => 'Sin asignar'] + $technicians->all()" placeholder="Técnico" />
        <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Zona" />
        <x-ui.select wire:model.live="filterSla" :options="['ok' => 'SLA en tiempo', 'risk' => 'SLA en riesgo', 'breached' => 'SLA vencido']" placeholder="Estado SLA" />
        <div class="flex gap-2">
            <x-ui.select wire:model.live="filterSource" :options="$this->sources()" placeholder="Origen" class="flex-1" />
            @if ($search || $filterPriority || $filterCategory || $filterTechnician || $filterZone || $filterSla || $filterSource || $filterStatus !== 'open')
                <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
            @endif
        </div>
    </div>

    {{-- ============================== LISTA ============================== --}}
    @if ($view === 'lista')
        <x-ui.card :padding="false">
            @can('tickets.manage')
                @if (count($selected))
                    <div class="flex flex-wrap items-center gap-3 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5">
                        <span class="text-sm font-semibold text-brand-700">{{ count($selected) }} seleccionado(s)</span>
                        <x-ui.select wire:model="bulkTechnician" :options="$technicians" placeholder="Asignar a técnico..." class="w-56" />
                        <x-ui.button size="sm" icon="user-plus" wire:click="bulkAssign">Asignar</x-ui.button>
                        <button wire:click="$set('selected', [])" class="text-xs text-slate-500 hover:text-slate-700">Quitar selección</button>
                    </div>
                @endif
            @endcan
            <x-ui.table>
                <x-slot:head>
                    @can('tickets.manage')
                        <th class="w-8"><input type="checkbox" class="rounded border-slate-300" wire:click="toggleAllOnPage(@js($tickets->pluck('id')->all()))" @checked($tickets->count() && count(array_intersect($tickets->pluck('id')->map(fn ($i) => (string) $i)->all(), $selected)) === $tickets->count())></th>
                    @endcan
                    <x-ui.th sort="folio" :sort-by="$sortBy" :sort-dir="$sortDir">Folio</x-ui.th>
                    <th>Cliente / asunto</th>
                    <x-ui.th sort="priority" :sort-by="$sortBy" :sort-dir="$sortDir">Prioridad</x-ui.th>
                    <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                    <th>Técnico</th>
                    <x-ui.th sort="sla_due_at" :sort-by="$sortBy" :sort-dir="$sortDir">SLA</x-ui.th>
                    <x-ui.th sort="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Creado</x-ui.th>
                    <th></th>
                </x-slot:head>
                @forelse ($tickets as $t)
                    @php $st = $t->slaState(); @endphp
                    <tr wire:key="t{{ $t->id }}" @class(['bg-danger-50/40' => $st === 'breached' && $t->isOpen()])>
                        @can('tickets.manage')
                            <td><input type="checkbox" class="rounded border-slate-300" wire:model.live="selected" value="{{ $t->id }}"></td>
                        @endcan
                        <td class="whitespace-nowrap">
                            <a href="{{ route('tickets.show', $t) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">{{ $t->folio }}</a>
                            <div class="text-[11px] text-slate-400">{{ $this->sources()[$t->source] ?? $t->source }}</div>
                        </td>
                        <td class="max-w-xs">
                            <div class="truncate font-medium text-slate-800">
                                @if ($t->client){{ $t->client->full_name }} <span class="text-xs font-normal text-slate-400">#{{ $t->client->code }}</span>
                                @elseif ($t->repeater)<span class="inline-flex items-center gap-1 text-danger-700"><x-heroicon-s-signal-slash class="h-3.5 w-3.5" /> Repetidor {{ $t->repeater->code }}</span>
                                @else — @endif
                            </div>
                            <div class="truncate text-xs text-slate-500">{{ $t->subject }} · {{ config('wisp.ticket_categories.'.$t->category, $t->category) }}</div>
                        </td>
                        <td><x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" /></td>
                        <td><x-ui.status catalog="ticket_status" :value="$t->status" size="xs" /></td>
                        <td class="text-xs">
                            @if ($t->technician)<span class="flex items-center gap-1.5"><x-ui.avatar :name="$t->technician->name" size="sm" /> {{ $t->technician->name }}</span>
                            @else<span class="text-slate-400">Sin asignar</span>@endif
                        </td>
                        <td>@include('livewire.tickets.partials.sla', ['ticket' => $t])</td>
                        <td class="text-xs whitespace-nowrap text-slate-600">{{ $t->created_at->format('d/m/Y H:i') }}<div class="text-slate-400">{{ $t->client?->zone?->name }}</div></td>
                        <td class="text-right"><x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('tickets.show', $t)" /></td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="9" icon="ticket" title="No hay tickets" description="Ajusta los filtros o registra un nuevo ticket." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $tickets->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ============================== TABLERO ============================== --}}
    @if ($view === 'tablero')
        <p class="mb-3 text-xs text-slate-500">
            @can('tickets.manage') Arrastra las tarjetas entre columnas para cambiar el estado. @endcan
            La columna «Resuelto» muestra los últimos 3 días.
        </p>
        <div class="flex gap-4 overflow-x-auto pb-4" x-data="{ over: null }">
            @foreach ($board as $status => $col)
                @php $color = \App\Support\Catalog::color('ticket_status', $status); @endphp
                <div class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-100/80 ring-1 ring-slate-200/70 transition"
                     :class="over === '{{ $status }}' && 'ring-2 ring-brand-400 bg-brand-50/60'"
                     @can('tickets.manage')
                         x-on:dragover.prevent="over = '{{ $status }}'" x-on:dragleave="over = null"
                         x-on:drop.prevent="over = null; $wire.moveTicket(parseInt($event.dataTransfer.getData('text/plain')), '{{ $status }}')"
                     @endcan>
                    <div class="flex items-center justify-between px-3 py-2.5">
                        <x-ui.status catalog="ticket_status" :value="$status" />
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-slate-600 ring-1 ring-slate-200">{{ $col['total'] }}</span>
                    </div>
                    <div class="flex max-h-[68vh] min-h-[120px] flex-col gap-2 overflow-y-auto px-2 pb-2">
                        @forelse ($col['items'] as $t)
                            @php $prColor = ['critica' => 'border-l-danger-500', 'alta' => 'border-l-warning-500', 'media' => 'border-l-brand-500', 'baja' => 'border-l-success-500'][$t->priority] ?? 'border-l-slate-300'; @endphp
                            <a href="{{ route('tickets.show', $t) }}" wire:navigate wire:key="b{{ $t->id }}"
                               @can('tickets.manage') draggable="true" x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $t->id }}')" @endcan
                               class="block cursor-pointer rounded-lg border border-l-4 border-slate-200 {{ $prColor }} bg-white p-3 shadow-xs transition hover:shadow-md">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-bold text-brand-600">{{ $t->folio }}</span>
                                    <x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" :dot="false" />
                                </div>
                                <div class="mt-1 line-clamp-2 text-sm font-semibold text-slate-800">{{ $t->subject }}</div>
                                <div class="mt-0.5 truncate text-xs text-slate-500">{{ $t->client?->full_name ?? ($t->repeater ? 'Repetidor '.$t->repeater->code : '—') }}</div>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    @include('livewire.tickets.partials.sla', ['ticket' => $t])
                                    @if ($t->technician)<x-ui.avatar :name="$t->technician->name" size="sm" title="{{ $t->technician->name }}" />@else<span class="text-[11px] text-slate-400">Sin técnico</span>@endif
                                </div>
                            </a>
                        @empty
                            <div class="rounded-lg border border-dashed border-slate-300 py-8 text-center text-xs text-slate-400">Sin tickets</div>
                        @endforelse
                        @if ($col['total'] > $col['items']->count())
                            <div class="py-1 text-center text-[11px] text-slate-500">+{{ $col['total'] - $col['items']->count() }} más · usa la vista de lista</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ============================== MODALES ============================== --}}
    <x-ui.modal wire:model="showCreate" title="Nuevo ticket" size="2xl">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="relative sm:col-span-2">
                <label class="form-label">Cliente <span class="text-danger-600">*</span></label>
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input type="text" wire:model.live.debounce.300ms="clientSearch" placeholder="Busca por nombre, código, teléfono, IP..." autocomplete="off"
                           class="form-input pl-9 {{ $form['client_id'] ? 'border-success-500 bg-success-50/40' : '' }}">
                    @if ($form['client_id'])<x-heroicon-s-check-circle class="absolute top-1/2 right-3 h-5 w-5 -translate-y-1/2 text-success-600" />@endif
                </div>
                @if ($clientResults->isNotEmpty())
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                        @foreach ($clientResults as $c)
                            <button type="button" wire:click="selectClient({{ $c->id }})" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50">
                                <x-ui.avatar :name="$c->full_name" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-slate-800">{{ $c->full_name }} <span class="font-normal text-slate-400">#{{ $c->code }}</span></span>
                                    <span class="block truncate text-xs text-slate-500">{{ $c->address }} · {{ $c->zone?->name }}</span>
                                </span>
                                <x-ui.status catalog="client_status" :value="$c->service_status" size="xs" />
                            </button>
                        @endforeach
                    </div>
                @elseif (! $form['client_id'] && mb_strlen(trim($clientSearch)) >= 2)
                    <p class="mt-1 text-xs text-slate-500">Sin coincidencias.</p>
                @endif
                @error('form.client_id')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
            </div>
            <x-ui.select label="Categoría" wire:model="form.category" :options="config('wisp.ticket_categories')" />
            <x-ui.select label="Prioridad" wire:model="form.priority" :options="config('wisp.ticket_priorities')" />
            <x-ui.select label="Técnico" wire:model="form.technician_id" :options="$technicians" placeholder="Sin asignar" />
            <x-ui.select label="Origen" wire:model="form.source" :options="$this->sources()" />
            <x-ui.select label="Repetidor relacionado" wire:model="form.repeater_id" :options="$repeaters" placeholder="Del cliente / ninguno" hint="Para fallas de infraestructura sin cliente específico." class="sm:col-span-2" />
            <x-ui.input label="Asunto" wire:model="form.subject" placeholder="Si lo dejas vacío se usa la categoría" class="sm:col-span-2" />
            <x-ui.textarea label="Descripción" wire:model="form.description" rows="4" class="sm:col-span-2" placeholder="Síntomas, horario de disponibilidad del cliente, referencias..." />
        </div>
        <p class="mt-3 text-xs text-slate-500">El SLA se calcula según la prioridad y se notifica al cliente por WhatsApp.</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="create">Crear ticket</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showResolve" title="Resolver ticket" size="md">
        <x-ui.textarea label="Solución aplicada" wire:model="resolveNote" rows="3" placeholder="Ej. Se realineó la antena y se cambió el conector RJ45" required />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="success" icon="check" wire:click="resolve">Marcar como resuelto</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
