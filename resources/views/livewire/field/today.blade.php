<div class="mx-auto max-w-2xl"
     x-data="{ busy: null, showMap: false,
               gps(method, ...args) {
                   this.busy = method + (args[0] || '');
                   const run = (lat, lng) => this.$wire.call(method, ...args, lat, lng).finally(() => this.busy = null);
                   if (!navigator.geolocation) return run(null, null);
                   navigator.geolocation.getCurrentPosition((p) => run(p.coords.latitude, p.coords.longitude), () => run(null, null), { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
               } }">

    {{-- Encabezado --}}
    <div class="relative mb-4 overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lg">
        <div class="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-brand-600/30"></div>
        <div class="relative">
            <div class="text-sm text-navy-200">{{ ucfirst(now()->translatedFormat('l d \d\e F')) }}</div>
            <h1 class="mt-0.5 text-2xl font-bold">Hola, {{ str($user->name)->before(' ') }}</h1>
            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-white/10 px-2 py-2.5"><div class="text-2xl font-bold">{{ count($stops) }}</div><div class="text-[11px] text-navy-200">Paradas</div></div>
                <div class="rounded-xl bg-white/10 px-2 py-2.5"><div class="text-2xl font-bold text-success-400">{{ $done }}</div><div class="text-[11px] text-navy-200">Atendidas</div></div>
                <div class="rounded-xl bg-white/10 px-2 py-2.5"><div class="text-2xl font-bold">{{ number_format((float) $distance, 1) }}</div><div class="text-[11px] text-navy-200">km aprox.</div></div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/15"><div class="h-2 rounded-full bg-success-500 transition-all" style="width: {{ count($stops) ? round($done / count($stops) * 100) : 0 }}%"></div></div>
            <div class="mt-4 grid grid-cols-2 gap-2">
                @if ($mapsUrl)
                    <a href="{{ $mapsUrl }}" target="_blank" class="flex items-center justify-center gap-1.5 rounded-xl bg-white py-2.5 text-sm font-semibold text-navy-900 active:scale-[.98]"><x-heroicon-o-map class="h-5 w-5" /> Ruta completa</a>
                @else
                    <span class="flex items-center justify-center gap-1.5 rounded-xl bg-white/10 py-2.5 text-sm text-navy-200"><x-heroicon-o-map class="h-5 w-5" /> Sin ruta</span>
                @endif
                <button type="button" @click="gps('shareLocation')" class="flex items-center justify-center gap-1.5 rounded-xl bg-white/15 py-2.5 text-sm font-semibold text-white active:scale-[.98]" :disabled="busy">
                    <x-heroicon-o-signal class="h-5 w-5" /> <span x-text="busy === 'shareLocation' ? 'Ubicando...' : 'Mi ubicación'"></span>
                </button>
            </div>
        </div>
    </div>

    @if (! $route && $stops)
        <div class="mb-4 flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50 p-3.5">
            <x-heroicon-o-sparkles class="h-6 w-6 shrink-0 text-brand-600" />
            <div class="flex-1 text-sm text-slate-700"><b>Ruta sugerida</b> con tus tickets e instalaciones, ordenada por urgencia y cercanía.</div>
            <x-ui.button size="sm" wire:click="startDay">Iniciar</x-ui.button>
        </div>
    @endif

    @if ($stops)
        <div class="mb-4">
            <button type="button" @click="showMap = !showMap; if (showMap) setTimeout(() => window.dispatchEvent(new CustomEvent('map-data-field-map', { detail: { data: @js($map) } })), 300)" class="flex w-full items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-xs">
                <span class="flex items-center gap-2"><x-heroicon-o-map-pin class="h-5 w-5 text-brand-600" /> Ver mapa de la ruta</span>
                <x-heroicon-m-chevron-down class="h-5 w-5 text-slate-400 transition" ::class="showMap && 'rotate-180'" />
            </button>
            <div x-show="showMap" x-collapse x-cloak class="mt-2">
                <x-ui.map id="field-map" :markers="$map['markers']" :lines="$map['lines']" height="260px" fit :layers-control="false" />
            </div>
        </div>
    @endif

    {{-- Paradas --}}
    <div class="space-y-3">
        @forelse ($stops as $i => $p)
            @php
                $st = $p['stop_status'] ?? 'pending';
                $isNext = $next && $next['key'] === $p['key'];
                $isTicket = $p['type'] === 'ticket';
                $nav = $p['lat'] ? 'https://www.google.com/maps/dir/?api=1&destination='.$p['lat'].','.$p['lng'] : null;
            @endphp
            <div wire:key="stop-{{ $p['key'] }}" @class([
                'overflow-hidden rounded-2xl border bg-white shadow-xs',
                'border-brand-300 ring-2 ring-brand-100' => $isNext,
                'border-slate-200' => ! $isNext,
                'opacity-70' => in_array($st, ['done', 'skipped']),
            ])>
                @if ($isNext)<div class="bg-brand-600 px-4 py-1 text-[11px] font-bold tracking-wide text-white uppercase">Siguiente parada</div>@endif
                <div class="flex gap-3 p-4">
                    <span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                        'bg-success-600 text-white' => $st === 'done', 'bg-warning-500 text-white' => $st === 'arrived',
                        'bg-slate-200 text-slate-500' => $st === 'skipped', 'bg-navy-900 text-white' => $st === 'pending'])>
                        @if ($st === 'done')<x-heroicon-m-check class="h-5 w-5" />@else{{ $p['sequence'] ?? $i + 1 }}@endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.badge :color="$p['badge'][1]" size="xs">{{ $p['badge'][0] }}</x-ui.badge>
                            @if ($isTicket && $p['sla'] && in_array($p['sla'], ['risk', 'breached']))
                                <x-ui.badge :color="$p['sla'] === 'breached' ? 'danger' : 'warning'" size="xs" dot>{{ $p['sla'] === 'breached' ? 'SLA vencido' : 'SLA por vencer' }}</x-ui.badge>
                            @endif
                            @if ($st !== 'pending')<x-ui.status catalog="route_stop_status" :value="$st" size="xs" />@endif
                        </div>
                        <div class="mt-1 text-base leading-tight font-bold text-navy-900">{{ $p['title'] }}</div>
                        <div class="text-sm text-slate-600">{{ $p['subtitle'] }}</div>
                        @if ($p['address'])<div class="mt-1 flex items-start gap-1 text-xs text-slate-500"><x-heroicon-m-map-pin class="mt-px h-3.5 w-3.5 shrink-0" />{{ $p['address'] }}</div>@endif
                        @if (! empty($p['arrived_at']))<div class="mt-1 text-xs text-slate-500">Llegada {{ $p['arrived_at'] }}{{ ! empty($p['departed_at']) ? ' · salida '.$p['departed_at'] : '' }}</div>@endif
                    </div>
                    @if ($p['phone'])
                        <a href="tel:{{ $p['phone'] }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600" title="Llamar"><x-heroicon-s-phone class="h-5 w-5" /></a>
                    @endif
                </div>

                @if (! in_array($st, ['done', 'skipped']))
                    <div class="grid grid-cols-2 gap-2 border-t border-slate-100 bg-slate-50/60 p-3">
                        @if ($nav)
                            <a href="{{ $nav }}" target="_blank" class="flex items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white py-3 text-sm font-semibold text-slate-700 active:scale-[.98]"><x-heroicon-o-map class="h-5 w-5 text-brand-600" /> Navegar</a>
                        @else
                            <span class="flex items-center justify-center rounded-xl border border-dashed border-slate-300 py-3 text-xs text-slate-400">Sin coordenadas</span>
                        @endif

                        @if ($st === 'pending')
                            @if ($isTicket)
                                <button type="button" @click="gps('onWay', '{{ $p['key'] }}')" :disabled="busy" class="flex items-center justify-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 py-3 text-sm font-semibold text-brand-700 active:scale-[.98]">
                                    <x-heroicon-o-truck class="h-5 w-5" /> <span x-text="busy === 'onWay{{ $p['key'] }}' ? 'Avisando...' : 'En camino'"></span>
                                </button>
                            @endif
                            <button type="button" @click="gps('arrive', '{{ $p['key'] }}')" :disabled="busy" @class(['flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]', 'col-span-2' => $isTicket, 'col-span-1' => ! $isTicket])>
                                <x-heroicon-o-map-pin class="h-5 w-5" /> <span x-text="busy === 'arrive{{ $p['key'] }}' ? 'Obteniendo GPS...' : 'Llegué'"></span>
                            </button>
                        @else
                            @if ($isTicket)
                                <a href="{{ route('field.work', $p['id']) }}" wire:navigate class="flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]"><x-heroicon-o-wrench-screwdriver class="h-5 w-5" /> Trabajar</a>
                            @elseif ($p['type'] === 'installation_order' && Route::has('installations.show'))
                                <a href="{{ route('installations.show', $p['id']) }}" wire:navigate class="flex items-center justify-center gap-1.5 rounded-xl bg-teal-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]"><x-heroicon-o-clipboard-document-check class="h-5 w-5" /> Ver orden</a>
                            @else
                                <span></span>
                            @endif
                            <button type="button" @click="gps('depart', '{{ $p['key'] }}')" :disabled="busy" class="col-span-2 flex items-center justify-center gap-1.5 rounded-xl bg-success-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]">
                                <x-heroicon-o-arrow-right-start-on-rectangle class="h-5 w-5" /> <span x-text="busy === 'depart{{ $p['key'] }}' ? 'Registrando...' : 'Salí · terminar parada'"></span>
                            </button>
                        @endif
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs">
                        @if ($isTicket)
                            <a href="{{ route('field.work', $p['id']) }}" wire:navigate class="font-semibold text-brand-600">Abrir ticket →</a>
                        @elseif ($p['type'] === 'installation_order' && Route::has('installations.show'))
                            <a href="{{ route('installations.show', $p['id']) }}" wire:navigate class="font-semibold text-teal-700">Abrir orden de instalación →</a>
                        @else
                            <span></span>
                        @endif
                        @if ($route && $st === 'pending')
                            <button type="button" wire:click="skip('{{ $p['key'] }}')" wire:confirm="¿Omitir esta parada?" class="text-slate-400 hover:text-danger-600">Omitir</button>
                        @endif
                    </div>
                @elseif ($isTicket)
                    <div class="border-t border-slate-100 px-4 py-2 text-xs"><a href="{{ route('field.work', $p['id']) }}" wire:navigate class="font-semibold text-brand-600">Ver ticket →</a></div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-slate-200 bg-white">
                <x-ui.empty icon="face-smile" title="Sin actividades para hoy" description="No tienes tickets abiertos ni instalaciones programadas. Tu supervisor puede asignarte una ruta." />
            </div>
        @endforelse
    </div>
</div>
