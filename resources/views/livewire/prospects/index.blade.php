<div>
    <x-ui.page-header title="Prospectos" subtitle="Interesados, cobertura y concentración de demanda">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-xs">
            @foreach (['lista' => ['Lista', 'list-bullet'], 'tablero' => ['Tablero', 'view-columns'], 'mapa' => ['Mapa', 'map']] as $key => [$label, $icon])
                <button type="button" wire:click="setView('{{ $key }}')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', 'bg-navy-900 text-white' => $view === $key, 'text-slate-600 hover:bg-slate-50' => $view !== $key])>
                    <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" /> {{ $label }}
                </button>
            @endforeach
        </div>
        <x-ui.button icon="plus" wire:click="create">Nuevo prospecto</x-ui.button>
    </x-ui.page-header>

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
        <x-ui.stat variant="soft" label="Prospectos abiertos" :value="number_format($kpi['open'])" icon="user-plus" color="brand" />
        <x-ui.stat variant="soft" label="Nuevos (30 días)" :value="number_format($kpi['recent'])" icon="sparkles" color="purple" />
        <x-ui.stat variant="soft" label="Con cobertura" :value="number_format($kpi['covered'])" icon="signal" color="success" />
        <x-ui.stat variant="soft" label="Sin cobertura" :value="number_format($kpi['uncovered'])" icon="signal-slash" color="danger" />
        <x-ui.stat variant="soft" label="Convertidos (30 días)" :value="number_format($kpi['converted30'])" icon="check-badge" color="teal" />
        <x-ui.stat variant="soft" label="Tasa de conversión" :value="pct($kpi['conversion'])" icon="arrow-trending-up" color="navy" />
    </div>

    {{-- Concentración de demanda --}}
    <x-ui.card class="mb-5" :padding="false">
        <x-slot:title>Concentración de demanda</x-slot:title>
        <x-slot:subtitle>Prospectos abiertos por zona y por celdas de ~{{ config('wisp.growth.cluster_km', 1) }} km · cobertura potencial ≤ {{ config('wisp.growth.potential_coverage_km', 4) }} km del repetidor más cercano</x-slot:subtitle>
        <x-slot:actions>
            @if (Route::has('map.index'))<x-ui.button size="sm" variant="ghost" icon="map" :href="route('map.index')">Mapa inteligente</x-ui.button>@endif
        </x-slot:actions>
        <div class="grid grid-cols-1 divide-y divide-slate-100 lg:grid-cols-2 lg:divide-x lg:divide-y-0">
            <div class="p-4">
                <div class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Por zona</div>
                <div class="space-y-2">
                    @forelse ($demandZones as $z)
                        <button type="button" wire:click="$set('filterZone', '{{ $z['zone_id'] ?? 'none' }}')" class="block w-full rounded-lg border border-slate-200 p-3 text-left transition hover:border-brand-300 hover:bg-brand-50/40">
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2 font-semibold text-navy-900"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $z['color'] }}"></span>{{ $z['name'] }}</span>
                                <x-ui.badge :color="$z['coverage_pct'] >= 70 ? 'success' : ($z['coverage_pct'] >= 40 ? 'warning' : 'danger')" size="xs">{{ $z['coverage_pct'] }}% con cobertura</x-ui.badge>
                            </div>
                            <div class="mt-1 text-sm text-slate-600">
                                <b class="text-navy-900">{{ $z['total'] }}</b> interesados ·
                                <b class="text-success-700">{{ $z['potential'] }}</b> con cobertura potencial ·
                                <b class="text-purple-700">{{ $z['recent'] }}</b> solicitudes en últimos 30 días
                            </div>
                            <x-ui.progress :value="$z['coverage_pct']" :color="$z['coverage_pct'] >= 70 ? 'success' : ($z['coverage_pct'] >= 40 ? 'warning' : 'danger')" height="h-1.5" class="mt-2" />
                        </button>
                    @empty
                        <x-ui.empty icon="map-pin" title="Sin demanda registrada" />
                    @endforelse
                </div>
            </div>
            <div class="p-4">
                <div class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Puntos de concentración (~{{ config('wisp.growth.cluster_km', 1) }} km)</div>
                <div class="divide-y divide-slate-100">
                    @forelse ($demandClusters as $c)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-800">{{ $c['zone_name'] ?? 'Sin zona' }} · {{ number_format($c['lat'], 4) }}, {{ number_format($c['lng'], 4) }}</div>
                                <div class="text-xs text-slate-500">{{ $c['total'] }} interesados · {{ $c['covered'] }} con cobertura · {{ $c['recent'] }} recientes @if ($c['nearest']) · {{ $c['nearest'] }} a {{ $c['nearest_km'] }} km ({{ round($c['nearest_util']) }}% uso)@endif</div>
                            </div>
                            <x-ui.badge :color="$c['coverage_pct'] < 50 ? 'danger' : 'success'" size="xs">{{ $c['uncovered'] }} sin cobertura</x-ui.badge>
                        </div>
                    @empty
                        <p class="py-4 text-sm text-slate-500">No hay concentraciones relevantes.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </x-ui.card>

    {{-- Filtros --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterStatus', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== ''])>
            Todos <span class="ml-1 opacity-70">{{ number_format($counts->sum()) }}</span>
        </button>
        <button wire:click="$set('filterStatus', 'abiertos')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === 'abiertos', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== 'abiertos'])>
            Abiertos <span class="ml-1 opacity-70">{{ number_format($kpi['open']) }}</span>
        </button>
        @foreach (config('wisp.prospect_status') as $key => [$label, $color])
            <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ number_format($counts[$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-6">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Nombre, teléfono, dirección..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterZone" placeholder="Todas las zonas">
                @foreach ($zones as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                <option value="none">Sin zona</option>
            </x-ui.select>
            <x-ui.select wire:model.live="filterSource" :options="config('wisp.prospect_sources')" placeholder="Todas las fuentes" />
            <x-ui.select wire:model.live="filterCoverage" :options="['si' => 'Con cobertura', 'no' => 'Sin cobertura', 'sin_ubicacion' => 'Sin ubicación']" placeholder="Cobertura" />
            <div class="flex gap-2">
                <x-ui.select wire:model.live="filterAssigned" :options="$users" placeholder="Asignado a" class="flex-1" />
                @if ($search || $filterZone || $filterSource || $filterAssigned || $filterCoverage !== '' || $filterStatus)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                @endif
            </div>
        </div>

        {{-- ============================== LISTA ============================== --}}
        @if ($view === 'lista')
            <x-ui.table>
                <x-slot:head>
                    <x-ui.th sort="name" :sort-by="$sortBy" :sort-dir="$sortDir">Prospecto</x-ui.th>
                    <th>Dirección / zona</th>
                    <th>Plan deseado</th>
                    <x-ui.th sort="distance_km" :sort-by="$sortBy" :sort-dir="$sortDir">Cobertura</x-ui.th>
                    <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                    <th>Fuente / asignado</th>
                    <x-ui.th sort="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th>
                    <th></th>
                </x-slot:head>
                @forelse ($prospects as $p)
                    @php $wa = $service->whatsappUrl($p); @endphp
                    <tr wire:key="p{{ $p->id }}">
                        <td>
                            <button type="button" wire:click="edit({{ $p->id }})" class="flex items-center gap-3 text-left">
                                <x-ui.avatar :name="$p->name" color="purple" />
                                <span class="min-w-0">
                                    <span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $p->name }}</span>
                                    <span class="block text-xs text-slate-500">{{ $p->phone ?: $p->whatsapp }}@if ($p->contacted_at) · contactado {{ $p->contacted_at->diffForHumans() }}@endif</span>
                                </span>
                            </button>
                        </td>
                        <td class="text-xs"><div class="max-w-[220px] truncate text-slate-700">{{ $p->address ?: '—' }}</div><div class="text-slate-500">{{ $p->zone?->name ?? 'Sin zona' }}</div></td>
                        <td>
                            @if ($p->desiredPlan)<span class="inline-flex items-center gap-1.5 text-sm font-medium"><span class="h-2 w-2 rounded-full" style="background: {{ $p->desiredPlan->color }}"></span>{{ $p->desiredPlan->name }}</span>@else<span class="text-slate-400">—</span>@endif
                        </td>
                        <td class="text-xs">
                            @if ($p->lat)
                                @if ($p->has_coverage)<x-ui.badge color="success" dot size="xs">Con cobertura</x-ui.badge>@else<x-ui.badge color="danger" dot size="xs">Sin cobertura</x-ui.badge>@endif
                                <div class="mt-0.5 text-slate-500">{{ $p->nearestRepeater?->code }} · {{ $p->distance_km }} km</div>
                            @else
                                <span class="text-slate-400">Sin ubicación</span>
                            @endif
                        </td>
                        <td><x-ui.status catalog="prospect_status" :value="$p->status" /></td>
                        <td class="text-xs"><div class="text-slate-700">{{ \App\Support\Catalog::label('prospect_sources', $p->source) }}</div><div class="text-slate-500">{{ $p->assignedTo?->name ?? 'Sin asignar' }}</div></td>
                        <td class="text-xs whitespace-nowrap text-slate-600">{{ $p->created_at?->format('d/m/Y') }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($wa)
                                    <a href="{{ $wa }}" target="_blank" wire:click="contacted({{ $p->id }})" class="rounded-lg p-1.5 text-success-600 hover:bg-success-50" title="Contactar por WhatsApp">
                                        <x-heroicon-o-chat-bubble-left-ellipsis class="h-5 w-5" />
                                    </a>
                                @endif
                                @include('livewire.prospects.partials.actions', ['p' => $p])
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="user-plus" title="No hay prospectos" description="Ajusta los filtros o registra un nuevo interesado." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $prospects->links() }}</div>
        @endif

        {{-- ============================== TABLERO ============================== --}}
        @if ($view === 'tablero')
            <div class="flex gap-3 overflow-x-auto p-4">
                @foreach ($columns as $status => $col)
                    <div class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-50 ring-1 ring-slate-200/70" wire:key="col-{{ $status }}">
                        <div class="flex items-center justify-between px-3 py-2.5">
                            <x-ui.status catalog="prospect_status" :value="$status" />
                            <span class="text-xs font-semibold text-slate-500">{{ $col['total'] }}</span>
                        </div>
                        <div class="max-h-[560px] space-y-2 overflow-y-auto px-2 pb-2">
                            @forelse ($col['items'] as $p)
                                @php $wa = $service->whatsappUrl($p); @endphp
                                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-xs" wire:key="b{{ $p->id }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <button type="button" wire:click="edit({{ $p->id }})" class="min-w-0 text-left">
                                            <div class="truncate text-sm font-semibold text-slate-800 hover:text-brand-600">{{ $p->name }}</div>
                                            <div class="truncate text-xs text-slate-500">{{ $p->address ?: ($p->phone ?: $p->whatsapp) }}</div>
                                        </button>
                                        @include('livewire.prospects.partials.actions', ['p' => $p])
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        @if ($p->desiredPlan)<x-ui.badge color="brand" size="xs">{{ $p->desiredPlan->name }}</x-ui.badge>@endif
                                        @if ($p->lat)
                                            <x-ui.badge :color="$p->has_coverage ? 'success' : 'danger'" size="xs">{{ $p->has_coverage ? 'Cobertura' : 'Sin cobertura' }} · {{ $p->distance_km }} km</x-ui.badge>
                                        @endif
                                    </div>
                                    <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                                        <span>{{ $p->created_at?->format('d/m/Y') }} · {{ \App\Support\Catalog::label('prospect_sources', $p->source) }}</span>
                                        @if ($wa)<a href="{{ $wa }}" target="_blank" wire:click="contacted({{ $p->id }})" class="font-semibold text-success-600">WhatsApp</a>@endif
                                    </div>
                                </div>
                            @empty
                                <p class="px-2 py-6 text-center text-xs text-slate-400">Sin prospectos</p>
                            @endforelse
                            @if ($col['total'] > $col['items']->count())
                                <button type="button" wire:click="showAll('{{ $status }}')" class="w-full py-1 text-center text-xs font-semibold text-brand-600">Ver los {{ $col['total'] }} →</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================== MAPA ============================== --}}
        @if ($view === 'mapa')
            <div class="relative">
                <x-ui.map id="prospects-map" :markers="$map['markers']" :circles="$map['circles']" :heat="$map['heat']" height="560px" fit cluster class="rounded-none border-0" />
                <div class="pointer-events-none absolute bottom-3 left-3 z-[400] rounded-lg bg-white/95 px-3 py-2 text-xs shadow ring-1 ring-slate-200">
                    <div class="mb-1 font-semibold text-slate-700">Prospectos</div>
                    <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-purple-600"></span>Con cobertura</div>
                    <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Sin cobertura</div>
                    <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-teal-600"></span>Convertido</div>
                    <div class="mt-1 text-slate-500">Calor = concentración de demanda</div>
                </div>
            </div>
        @endif
    </x-ui.card>

    {{-- ============================== MODAL: PROSPECTO ============================== --}}
    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar prospecto' : 'Nuevo prospecto'" size="4xl">
        <form wire:submit="save" id="prospect-form" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="grid grid-cols-2 gap-3 content-start">
                <x-ui.input label="Nombre completo" wire:model="form.name" filter="name" class="col-span-2" required />
                <x-ui.input label="Teléfono" wire:model="form.phone" inputmode="tel" filter="phone" />
                <x-ui.input label="WhatsApp" wire:model="form.whatsapp" inputmode="tel" filter="phone" />
                <x-ui.input label="Correo" type="email" wire:model="form.email" filter="email" class="col-span-2" />
                <x-ui.input label="Dirección" wire:model="form.address" filter="text" maxlength="190" class="col-span-2" />
                <x-ui.select label="Plan deseado" wire:model="form.desired_plan_id" :options="$plans->mapWithKeys(fn ($pl) => [$pl->id => $pl->name.' · '.$pl->speedLabel().' · '.money($pl->price)])" placeholder="Sin definir" />
                <x-ui.select label="Zona" wire:model="form.zone_id" :options="$zones" placeholder="Automática" />
                <x-ui.select label="Fuente" wire:model="form.source" :options="config('wisp.prospect_sources')" required />
                <x-ui.select label="Estado" wire:model="form.status" :options="config('wisp.prospect_status')" required />
                <x-ui.select label="Asignado a" wire:model="form.assigned_to" :options="$users" placeholder="Sin asignar" />
                <x-ui.input label="Fecha de solicitud" type="date" wire:model="form.date" />
                <x-ui.input label="Código de referido" wire:model="form.referral_code" filter="upper" placeholder="INVITA-1234" class="col-span-2" />
                <x-ui.textarea label="Notas" wire:model="form.notes" rows="2" maxlength="2000" class="col-span-2" />
            </div>
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <span class="form-label mb-0">Ubicación (clic en el mapa)</span>
                    <button type="button" x-data="geo('form.lat', 'form.lng')" @click="locate()" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600">
                        <x-heroicon-m-map-pin class="h-4 w-4" /><span x-text="locating ? 'Obteniendo...' : 'Usar mi ubicación'"></span>
                    </button>
                </div>
                <div wire:key="picker-{{ $editingId ?? 'new' }}-{{ $showForm ? 1 : 0 }}">
                    <x-ui.map id="prospect-picker" picker picker-lat="form.lat" picker-lng="form.lng" height="320px" :zoom="14"
                              :value="$form['lat'] ? ['lat' => (float) $form['lat'], 'lng' => (float) $form['lng']] : null"
                              :center="$form['lat'] ? [(float) $form['lat'], (float) $form['lng']] : null"
                              :circles="\App\Support\MapData::coverageCircles()" :layers-control="false" />
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <x-ui.input wire:model.live.debounce.600ms="form.lat" filter="signed" placeholder="Latitud" />
                    <x-ui.input wire:model.live.debounce.600ms="form.lng" filter="signed" placeholder="Longitud" />
                </div>
                @if ($coverage)
                    <div @class(['mt-3 flex items-start gap-2 rounded-lg p-3 text-sm', 'bg-success-50 text-success-700' => $coverage['ok'], 'bg-danger-50 text-danger-700' => ! $coverage['ok']])>
                        <x-dynamic-component :component="$coverage['ok'] ? 'heroicon-o-signal' : 'heroicon-o-signal-slash'" class="mt-0.5 h-5 w-5 shrink-0" />
                        <div>
                            <div class="font-semibold">{{ $coverage['ok'] ? 'Con cobertura' : 'Sin cobertura' }}</div>
                            <div class="text-xs">{{ $coverage['message'] }}</div>
                            @if (! $coverage['ok'])<div class="mt-1 text-xs">Al guardar, el estado cambiará a "Sin cobertura".</div>@endif
                        </div>
                    </div>
                @endif
            </div>
        </form>
        <x-slot:footer>
            @if ($editingId)
                <x-ui.button variant="ghost" class="mr-auto text-danger-600" icon="trash" wire:click="delete({{ $editingId }})" wire:confirm="¿Eliminar este prospecto?">Eliminar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="prospect-form" icon="check" wire:loading.attr="disabled" wire:target="save">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ============================== MODAL: ORDEN ============================== --}}
    <x-ui.modal wire:model="showOrder" title="Crear cotización / orden de instalación" size="lg">
        <form wire:submit="createOrder" id="order-form" class="space-y-3">
            <p class="text-sm text-slate-500">Se crea la orden en etapa de <b>cotización</b> con el costo de instalación del plan. Podrás editar partidas, aceptar y programar desde la orden.</p>
            <x-ui.select label="Plan" wire:model="order.plan_id" :options="$plans->mapWithKeys(fn ($pl) => [$pl->id => $pl->name.' · '.$pl->speedLabel().' · '.money($pl->price).' · instalación '.money($pl->installation_fee)])" placeholder="Selecciona..." required />
            <x-ui.select label="Promoción" wire:model="order.promotion_id" :options="$promotions->mapWithKeys(fn ($pr) => [$pr->id => $pr->name.' · '.$pr->valueLabel()])" placeholder="Sin promoción" />
            <x-ui.select label="Técnico (opcional)" wire:model="order.technician_id" :options="$technicians" placeholder="Asignar después" />
            <x-ui.textarea label="Notas" wire:model="order.notes" rows="2" maxlength="1000" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="order-form" icon="clipboard-document-check">Crear orden</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ============================== MODAL: CONVERTIR ============================== --}}
    <x-ui.modal wire:model="showConvert" title="Convertir a cliente" size="md">
        <form wire:submit="convertToClient" id="convert-form" class="space-y-3">
            <p class="text-sm text-slate-500">Se creará el cliente con estado <b>Instalación pendiente</b>, usando los datos, ubicación y repetidor sugerido del prospecto. Si llegó con código de referido, se registrará el referido.</p>
            <x-ui.select label="Plan contratado" wire:model="convert.plan_id" :options="$plans->mapWithKeys(fn ($pl) => [$pl->id => $pl->name.' · '.money($pl->price)])" placeholder="Selecciona..." required />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="convert-form" variant="success" icon="user-plus">Convertir</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
