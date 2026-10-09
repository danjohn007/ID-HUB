<div>
    <x-ui.page-header title="Mapa inteligente" subtitle="Clientes, infraestructura, campo, fallas y demanda en una sola vista">
        <div class="hidden flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600 shadow-xs md:flex">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-600"></span>Operativo <b>{{ $statusCounts['online'] }}</b></span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-600"></span>Advertencia <b>{{ $statusCounts['warning'] }}</b></span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Fallo <b>{{ $statusCounts['offline'] }}</b></span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Sin comunicación <b>{{ $statusCounts['unknown'] }}</b></span>
            <span class="border-l border-slate-200 pl-3">Clientes en línea <b>{{ number_format((int) $clientCounts->online_n) }}</b> / {{ number_format((int) $clientCounts->total) }}</span>
        </div>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        {{-- Mapa --}}
        <div class="relative h-[60vh] min-h-[420px] lg:h-[calc(100vh-11rem)]">
            <x-ui.map id="main-map" :markers="$map['markers']" :circles="$map['circles']" :sectors="$map['sectors']" :heat="$map['heat']"
                      :center="$center" :zoom="13" cluster class="shadow-sm" height="100%" />
            <div class="pointer-events-none absolute bottom-3 left-3 z-[400] hidden rounded-lg bg-white/95 px-3 py-2 text-[11px] shadow ring-1 ring-slate-200 sm:block">
                <div class="mb-1 font-semibold text-slate-700">Estados</div>
                <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-600"></span>Operativo</div>
                <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-600"></span>Advertencia</div>
                <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Fallo</div>
                <div class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Sin comunicación</div>
            </div>
            <div wire:loading.delay class="absolute top-3 left-1/2 z-[400] -translate-x-1/2 rounded-full bg-navy-900/90 px-3 py-1 text-xs font-semibold text-white shadow">Actualizando mapa...</div>
        </div>

        {{-- Panel lateral --}}
        <div class="card flex flex-col overflow-hidden lg:h-[calc(100vh-11rem)]">
            <div class="flex border-b border-slate-200 text-sm font-semibold">
                @foreach (['capas' => 'Capas', 'fallas' => 'Fallas', 'expansion' => 'Expansión'] as $key => $label)
                    <button type="button" wire:click="$set('panel', '{{ $key }}')" @class(['flex-1 border-b-2 px-3 py-2.5 transition', 'border-brand-600 text-brand-700' => $panel === $key, 'border-transparent text-slate-500 hover:text-slate-700' => $panel !== $key])>
                        {{ $label }}
                        @if ($key === 'fallas' && $outageCount)<span class="ml-1 rounded-full bg-danger-600 px-1.5 text-[10px] text-white">{{ $outageCount }}</span>@endif
                    </button>
                @endforeach
            </div>

            <div class="flex-1 overflow-y-auto p-4">
                {{-- ====== CAPAS ====== --}}
                @if ($panel === 'capas')
                    <div class="mb-3 flex flex-wrap gap-1.5">
                        @foreach (['todo' => 'Todo', 'red' => 'Red', 'campo' => 'Campo', 'expansion' => 'Expansión'] as $k => $l)
                            <button type="button" wire:click="preset('{{ $k }}')" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-brand-50 hover:text-brand-700">{{ $l }}</button>
                        @endforeach
                    </div>
                    <div class="space-y-1">
                        @foreach ($layerDefs as $key => [$label, $icon, $color])
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-slate-50" wire:key="layer-{{ $key }}">
                                <input type="checkbox" value="{{ $key }}" wire:model.live="layers" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200">
                                <span class="flex h-7 w-7 items-center justify-center rounded-md text-white" style="background: {{ $color }}"><x-dynamic-component :component="'heroicon-s-'.$icon" class="h-4 w-4" /></span>
                                <span class="text-sm font-medium text-slate-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                        <div class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Filtros de clientes</div>
                        <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Todas las zonas" />
                        <x-ui.select wire:model.live="filterStatus" :options="config('wisp.client_status')" placeholder="Todos los estados" />
                        <x-ui.select wire:model.live="filterRepeater" :options="$repeaters" placeholder="Todos los repetidores" />
                        @if ($filterZone || $filterStatus || $filterRepeater)
                            <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">Limpiar filtros</x-ui.button>
                        @endif
                    </div>
                @endif

                {{-- ====== FALLAS / IMPACTO ====== --}}
                @if ($panel === 'fallas')
                    <div class="space-y-3">
                        @forelse ($outages as $o)
                            <div class="rounded-xl border border-danger-100 bg-danger-50/50 p-3" wire:key="out-{{ $o['key'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="text-[11px] font-bold tracking-wide text-danger-600 uppercase">Fuera de servicio</div>
                                        <div class="font-bold text-navy-900">{{ $o['title'] }}</div>
                                    </div>
                                    @if ($o['lat'])
                                        <button type="button" class="rounded-lg bg-white p-1.5 text-danger-600 shadow-xs ring-1 ring-danger-100 hover:bg-danger-50" title="Ubicar en el mapa"
                                                @click="$dispatch('map-focus-main-map', { lat: {{ $o['lat'] }}, lng: {{ $o['lng'] }}, zoom: 15 })">
                                            <x-heroicon-m-viewfinder-circle class="h-5 w-5" />
                                        </button>
                                    @endif
                                </div>
                                <div class="mt-2 flex items-baseline gap-1.5">
                                    <span class="text-2xl font-extrabold text-danger-600">{{ number_format($o['affected']) }}</span>
                                    <span class="text-xs text-slate-600">clientes afectados</span>
                                </div>
                                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                                    <div><dt class="text-slate-500">Zona</dt><dd class="font-semibold text-slate-700">{{ $o['zone'] ?? '—' }}</dd></div>
                                    <div><dt class="text-slate-500">Técnico</dt><dd class="font-semibold text-slate-700">{{ $o['technician'] ?? 'Sin asignar' }}</dd></div>
                                    <div><dt class="text-slate-500">Última comunicación</dt><dd class="font-semibold text-slate-700">{{ $o['last']?->format('d/m H:i') ?? '—' }}</dd></div>
                                    <div><dt class="text-slate-500">Tiempo caído</dt><dd class="font-semibold text-danger-600">{{ $o['since'] ? $o['since']->diffForHumans(null, true) : '—' }}</dd></div>
                                </dl>
                                @if ($o['cause'])<div class="mt-1.5 text-xs text-slate-500">{{ $o['cause'] }}</div>@endif
                                <div class="mt-2 border-t border-danger-100 pt-2">
                                    <div class="text-[11px] font-semibold text-slate-500">Tickets relacionados ({{ $o['tickets']->count() }})</div>
                                    @foreach ($o['tickets']->take(4) as $t)
                                        <a @if (Route::has('tickets.show')) href="{{ route('tickets.show', $t->id) }}" wire:navigate @endif class="mt-1 flex items-center gap-2 text-xs hover:text-brand-600">
                                            <x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" :dot="false" />
                                            <span class="font-semibold">{{ $t->folio }}</span><span class="truncate text-slate-500">{{ $t->subject }}</span>
                                        </a>
                                    @endforeach
                                </div>
                                @if ($o['url'])<a href="{{ $o['url'] }}" wire:navigate class="link mt-2 inline-block text-xs">Ver repetidor →</a>@endif
                            </div>
                        @empty
                            <x-ui.empty icon="check-circle" title="Sin fallas activas" description="Toda la infraestructura reporta comunicación." />
                        @endforelse
                    </div>
                @endif

                {{-- ====== EXPANSIÓN ====== --}}
                @if ($panel === 'expansion')
                    <div class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Alta demanda + baja cobertura</div>
                    <p class="mb-3 text-xs text-slate-500">Concentraciones de ≥ {{ config('wisp.growth.hotspot_min_prospects') }} interesados con ≤ {{ config('wisp.growth.hotspot_max_coverage_pct') }}% de cobertura: candidatas a nuevo repetidor.</p>
                    <div class="space-y-2">
                        @forelse ($hotspots as $h)
                            <div class="rounded-xl border border-warning-100 bg-warning-50/50 p-3" wire:key="hs-{{ $h['key'] }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="font-bold text-navy-900">{{ $h['zone_name'] ?? 'Sin zona' }}</div>
                                        <div class="text-[11px] text-slate-500">{{ number_format($h['lat'], 4) }}, {{ number_format($h['lng'], 4) }}</div>
                                    </div>
                                    <button type="button" class="rounded-lg bg-white p-1.5 text-warning-700 shadow-xs ring-1 ring-warning-100" title="Ubicar"
                                            @click="$dispatch('map-focus-main-map', { lat: {{ $h['lat'] }}, lng: {{ $h['lng'] }}, zoom: 15 })">
                                        <x-heroicon-m-viewfinder-circle class="h-5 w-5" />
                                    </button>
                                </div>
                                <div class="mt-1 text-sm text-slate-700"><b>{{ $h['total'] }}</b> interesados · <b class="text-danger-600">{{ $h['uncovered'] }}</b> sin cobertura · <b>{{ $h['recent'] }}</b> en 30 días</div>
                                <div class="mt-1 text-xs text-slate-500">Repetidor más cercano {{ $h['nearest'] ?? '—' }} a {{ $h['nearest_km'] }} km ({{ round($h['nearest_util'] ?? 0) }}% uso) · {{ $h['clients_near'] }} clientes a 1.5 km</div>
                            </div>
                        @empty
                            <p class="rounded-lg bg-slate-50 p-4 text-center text-xs text-slate-500">No hay zonas con alta demanda y baja cobertura.</p>
                        @endforelse
                    </div>
                    <div class="mt-5 mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Demanda por zona</div>
                    <div class="divide-y divide-slate-100">
                        @foreach ($zonesDemand as $z)
                            <button type="button" class="flex w-full items-center justify-between gap-2 py-2 text-left" @if ($z['lat']) @click="$dispatch('map-focus-main-map', { lat: {{ $z['lat'] }}, lng: {{ $z['lng'] }}, zoom: 14 })" @endif>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-slate-800">{{ $z['name'] }}</span>
                                    <span class="block text-xs text-slate-500">{{ $z['total'] }} interesados · {{ $z['potential'] }} con cobertura potencial · {{ $z['recent'] }} en 30 días</span>
                                </span>
                                <x-ui.badge :color="$z['coverage_pct'] >= 70 ? 'success' : ($z['coverage_pct'] >= 40 ? 'warning' : 'danger')" size="xs">{{ $z['coverage_pct'] }}%</x-ui.badge>
                            </button>
                        @endforeach
                    </div>
                    <x-ui.button size="sm" variant="secondary" icon="fire" class="mt-4 w-full" wire:click="preset('expansion')">Mostrar capas de expansión</x-ui.button>
                @endif
            </div>
        </div>
    </div>
</div>
