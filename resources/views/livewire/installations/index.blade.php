<div>
    <x-ui.page-header title="Instalaciones" subtitle="Órdenes de instalación: cotización, programación, ejecución y activación">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-xs">
            @foreach (['agenda' => ['Agenda', 'calendar-days'], 'lista' => ['Lista', 'list-bullet']] as $key => [$label, $icon])
                <button type="button" wire:click="setView('{{ $key }}')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', 'bg-navy-900 text-white' => $view === $key, 'text-slate-600 hover:bg-slate-50' => $view !== $key])>
                    <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" /> {{ $label }}
                </button>
            @endforeach
        </div>
        @can('installations.manage')
            <x-ui.button icon="plus" wire:click="$set('showCreate', true)">Nueva orden</x-ui.button>
        @endcan
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
        <x-ui.stat variant="soft" label="En proceso de alta" :value="$kpi['pending']" icon="clipboard-document-list" color="brand" />
        <x-ui.stat variant="soft" label="Programadas hoy" :value="$kpi['today']" icon="calendar" color="purple" />
        <x-ui.stat variant="soft" label="Próximos 7 días" :value="$kpi['week']" icon="calendar-days" color="teal" />
        <x-ui.stat variant="soft" label="Atrasadas" :value="$kpi['overdue']" icon="exclamation-triangle" :color="$kpi['overdue'] ? 'danger' : 'slate'" />
        <x-ui.stat variant="soft" label="Activadas (30 días)" :value="$kpi['activated30']" icon="bolt" color="success" />
    </div>

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterStatus', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== ''])>
            Todas <span class="ml-1 opacity-70">{{ number_format($counts->sum()) }}</span>
        </button>
        <button wire:click="$set('filterStatus', 'pendientes')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === 'pendientes', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== 'pendientes'])>
            Pendientes <span class="ml-1 opacity-70">{{ $kpi['pending'] }}</span>
        </button>
        @foreach (config('wisp.installation_status') as $key => [$label, $color])
            <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ number_format($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Folio, nombre o teléfono..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterTechnician" placeholder="Todos los técnicos">
                @foreach ($technicians as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                <option value="none">Sin técnico</option>
            </x-ui.select>
            <div>
                @if ($search || $filterStatus || $filterTechnician)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters">Limpiar filtros</x-ui.button>
                @endif
            </div>
        </div>

        @if ($view === 'lista')
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th sort="folio" :sort-by="$sortBy" :sort-dir="$sortDir">Folio</x-ui.th>
                    <th>Cliente / prospecto</th>
                    <th>Plan</th>
                    <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                    <x-ui.th sort="scheduled_at" :sort-by="$sortBy" :sort-dir="$sortDir">Programada</x-ui.th>
                    <th>Técnico</th>
                    <th>Checklist</th>
                    <x-ui.th sort="quote_amount" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Cotización</x-ui.th>
                    <th></th>
                </x-slot:head>
                @forelse ($orders as $o)
                    <tr wire:key="o{{ $o->id }}">
                        <td><a href="{{ route('installations.show', $o) }}" wire:navigate class="font-semibold text-brand-600">{{ $o->folio }}</a><div class="text-xs text-slate-400">{{ $o->created_at->format('d/m/Y') }}</div></td>
                        <td>
                            <div class="font-medium text-slate-800">{{ $o->customerName() }}</div>
                            <div class="max-w-[240px] truncate text-xs text-slate-500">{{ $o->client ? 'Cliente #'.$o->client->code : 'Prospecto' }} · {{ $o->client?->address ?? $o->prospect?->address }}</div>
                        </td>
                        <td>@if ($o->plan)<span class="inline-flex items-center gap-1.5 text-sm"><span class="h-2 w-2 rounded-full" style="background: {{ $o->plan->color }}"></span>{{ $o->plan->name }}</span>@endif</td>
                        <td><x-ui.status catalog="installation_status" :value="$o->status" /></td>
                        <td class="text-xs whitespace-nowrap {{ $o->scheduled_at && $o->scheduled_at->isPast() && in_array($o->status, ['aceptada', 'programada']) ? 'font-semibold text-danger-600' : 'text-slate-600' }}">{{ $o->scheduled_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-xs text-slate-600">{{ $o->technician?->name ?? '—' }}</td>
                        <td class="w-32"><x-ui.progress :value="$o->checklistProgress()" color="teal" label /></td>
                        <td class="text-right font-semibold">{{ money($o->quote_amount) }}</td>
                        <td class="text-right"><x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('installations.show', $o)" /></td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="9" icon="clipboard-document-check" title="No hay órdenes" description="Crea una orden desde un prospecto o con el botón Nueva orden." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $orders->links() }}</div>
        @else
            <div class="grid grid-cols-1 gap-5 p-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div class="min-w-0 space-y-4">
                    @if ($overdue->isNotEmpty())
                        <div>
                            <div class="mb-2 flex items-center gap-2 text-sm font-bold text-danger-600"><x-heroicon-o-exclamation-triangle class="h-5 w-5" /> Atrasadas ({{ $overdue->count() }})</div>
                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2 2xl:grid-cols-3">
                                @foreach ($overdue as $o)
                                    <div class="relative">
                                        <span class="absolute -top-2 right-2 z-10 rounded-full bg-danger-600 px-2 text-2xs font-semibold text-white">{{ $o->scheduled_at->format('d/m') }}</span>
                                        @include('livewire.installations.partials.card', ['o' => $o])
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($days as $day)
                            @php $isToday = $day['date']->isToday(); @endphp
                            <div @class(['rounded-xl p-3 ring-1', 'bg-brand-50/60 ring-brand-200' => $isToday, 'bg-slate-50 ring-slate-200/70' => ! $isToday, 'opacity-70' => $day['orders']->isEmpty() && ! $isToday])>
                                <div class="mb-2 flex items-center justify-between">
                                    <div>
                                        <span class="text-sm font-bold capitalize text-navy-900">{{ $isToday ? 'Hoy' : ($day['date']->isTomorrow() ? 'Mañana' : $day['date']->translatedFormat('l')) }}</span>
                                        <span class="ml-1 text-xs text-slate-500">{{ $day['date']->format('d/m/Y') }}</span>
                                    </div>
                                    <x-ui.badge :color="$day['orders']->isEmpty() ? 'slate' : 'brand'" size="xs">{{ $day['orders']->count() }}</x-ui.badge>
                                </div>
                                <div class="space-y-2">
                                    @forelse ($day['orders'] as $o)
                                        @include('livewire.installations.partials.card', ['o' => $o])
                                    @empty
                                        <p class="py-2 text-center text-xs text-slate-400">Sin instalaciones</p>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($later)
                        <p class="text-xs text-slate-500">+ {{ $later }} orden(es) programadas después de 14 días. <button class="link" wire:click="setView('lista')">Ver lista</button></p>
                    @endif
                </div>
                <div>
                    <div class="mb-2 text-sm font-bold text-navy-900">Por programar ({{ $unscheduled->count() }})</div>
                    <div class="space-y-2">
                        @forelse ($unscheduled as $o)
                            @include('livewire.installations.partials.card', ['o' => $o])
                        @empty
                            <p class="rounded-lg bg-slate-50 p-4 text-center text-xs text-slate-400">Todas las órdenes están programadas.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </x-ui.card>

    <x-ui.modal wire:model="showCreate" title="Nueva orden de instalación" size="lg">
        <form wire:submit="create" id="create-order" class="space-y-3">
            @if ($showCreate)
                <x-ui.select label="Prospecto" wire:model.live="form.prospect_id" :options="$prospects" placeholder="Selecciona un prospecto..." required
                             hint="Para un prospecto nuevo, regístralo primero en Prospectos (se valida cobertura)." />
                <x-ui.select label="Plan" wire:model="form.plan_id" :options="$plans" placeholder="Selecciona..." required />
                <x-ui.select label="Promoción" wire:model="form.promotion_id" :options="$promotions" placeholder="Sin promoción" />
                <x-ui.select label="Técnico" wire:model="form.technician_id" :options="$technicians" placeholder="Asignar después" />
                <x-ui.textarea label="Notas" wire:model="form.notes" rows="2" maxlength="1000" />
            @endif
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="create-order" icon="check">Crear orden</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
