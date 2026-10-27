<div>
    <x-ui.page-header title="Rutas de campo" subtitle="Agrupa actividades por zona, técnico o cobrador, fecha y prioridad, y genera la ruta sugerida">
        @if ($mapsUrl)
            <x-ui.button variant="secondary" icon="map" :href="$mapsUrl" :navigate="false" target="_blank">Abrir en Google Maps</x-ui.button>
        @endif
        <x-ui.button variant="secondary" icon="sparkles" wire:click="suggest">Ruta sugerida</x-ui.button>
        <x-ui.button icon="check" wire:click="save" :disabled="! $plan">Guardar ruta</x-ui.button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[380px_minmax(0,1fr)]">
        {{-- Panel de planeación --}}
        <div class="space-y-5">
            <x-ui.card title="Agrupar actividades">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['tecnico' => ['Técnico', 'wrench-screwdriver'], 'cobranza' => ['Cobranza', 'banknotes']] as $k => [$label, $icon])
                            <button wire:click="$set('type', '{{ $k }}')" @class(['flex items-center justify-center gap-1.5 rounded-lg border-2 py-2 text-sm font-semibold transition', 'border-brand-600 bg-brand-50 text-brand-700' => $type === $k, 'border-slate-200 text-slate-600 hover:border-slate-300' => $type !== $k])>
                                <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" /> {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <x-ui.input type="date" label="Fecha" wire:model.live="date" />
                    <x-ui.select :label="$type === 'tecnico' ? 'Técnico' : 'Cobrador'" wire:model.live="userId" placeholder="Selecciona..." name="userId">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}{{ $u->last_lat ? '' : ' (sin ubicación)' }}</option>
                        @endforeach
                    </x-ui.select>
                    <div class="grid grid-cols-2 gap-2">
                        <x-ui.select label="Zona" wire:model.live="zoneId" :options="$zones" placeholder="Todas" />
                        @if ($type === 'tecnico')
                            <x-ui.select label="Prioridad" wire:model.live="priority" :options="config('wisp.ticket_priorities')" placeholder="Todas" />
                        @endif
                    </div>
                    <div class="space-y-2 rounded-lg bg-slate-50 p-3">
                        @if ($type === 'tecnico')
                            <x-ui.toggle wire:model.live="includeTickets" label="Tickets abiertos" />
                            <x-ui.toggle wire:model.live="includeInstallations" label="Instalaciones programadas" />
                        @endif
                        <x-ui.toggle wire:model.live="includeUnassigned" :label="$type === 'tecnico' ? 'Incluir actividades sin técnico' : 'Incluir clientes sin cobrador'" description="Se asignan al guardar la ruta." />
                        <x-ui.toggle wire:model.live="byPriority" label="Atender primero lo más urgente" description="Optimiza por grupos de prioridad (crítica → baja)." />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :title="'Actividades ('.$includedCount.'/'.count($candidates).')'" :padding="false">
                <x-slot:actions>
                    <button wire:click="selectAll(true)" class="text-xs font-semibold text-brand-600 hover:underline">Todas</button>
                    <button wire:click="selectAll(false)" class="text-xs font-semibold text-slate-500 hover:underline">Ninguna</button>
                </x-slot:actions>
                <div class="max-h-[460px] divide-y divide-slate-100 overflow-y-auto">
                    @forelse ($candidates as $p)
                        @php $on = ! in_array($p['key'], $excluded, true); @endphp
                        <label wire:key="cand-{{ $p['key'] }}" @class(['flex cursor-pointer items-start gap-3 px-4 py-2.5 hover:bg-slate-50', 'opacity-50' => ! $on])>
                            <input type="checkbox" class="mt-1 rounded border-slate-300" @checked($on) wire:click="toggle('{{ $p['key'] }}')">
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-slate-800">{{ $p['title'] }}</span>
                                    <x-ui.badge :color="$p['badge'][1]" size="xs">{{ $p['badge'][0] }}</x-ui.badge>
                                </span>
                                <span class="block truncate text-xs text-slate-500">{{ $p['subtitle'] }}</span>
                                <span class="block truncate text-2xs text-slate-400">
                                    @if (! $p['lat'])<span class="text-warning-600">Sin coordenadas · </span>@endif
                                    @if (! $p['assigned'])<span class="text-purple-600">Sin asignar · </span>@endif
                                    {{ $p['address'] }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <x-ui.empty icon="inbox" title="Sin actividades" description="No hay pendientes con los filtros seleccionados." />
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        {{-- Mapa y ruta --}}
        <div class="min-w-0 space-y-5">
            <x-ui.card :padding="false">
                <x-slot:title>{{ $plan ? 'Ruta sugerida' : ($preview ? 'Ruta guardada · '.$preview->user?->name : 'Actividades en el mapa') }}</x-slot:title>
                <x-slot:actions>
                    @if ($plan)
                        <x-ui.badge color="brand">{{ count($plan) }} paradas</x-ui.badge>
                        <x-ui.badge color="navy">{{ number_format($planDistance, 1) }} km</x-ui.badge>
                    @elseif ($preview)
                        <x-ui.badge color="brand">{{ $preview->stops->count() }} paradas</x-ui.badge>
                        @if ($preview->distance_km)<x-ui.badge color="navy">{{ number_format($preview->distance_km, 1) }} km</x-ui.badge>@endif
                    @endif
                </x-slot:actions>
                <x-ui.map id="route-map" :markers="$map['markers']" :lines="$map['lines']" height="420px" fit class="rounded-t-none border-0" />
            </x-ui.card>

            @if ($plan)
                <x-ui.card title="Orden sugerido" :subtitle="($planStart['label'] ?? 'Inicio').' → '.count($plan).' paradas · '.number_format($planDistance, 1).' km en línea recta (vecino más cercano + 2-opt)'" :padding="false">
                    <x-slot:actions>
                        <x-ui.button size="sm" icon="check" wire:click="save">Guardar ruta</x-ui.button>
                    </x-slot:actions>
                    @error('userId')<p class="border-b border-danger-100 bg-danger-50 px-5 py-2 text-xs font-medium text-danger-700">{{ $message }}</p>@enderror
                    <ol class="divide-y divide-slate-100">
                        @foreach ($plan as $i => $p)
                            <li wire:key="plan-{{ $p['key'] }}" class="flex items-center gap-3 px-5 py-2.5">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-navy-900 text-sm font-bold text-white">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2"><span class="truncate text-sm font-semibold text-slate-800">{{ $p['title'] }}</span><x-ui.badge :color="$p['badge'][1]" size="xs">{{ $p['badge'][0] }}</x-ui.badge></div>
                                    <div class="truncate text-xs text-slate-500">{{ $p['subtitle'] }} · {{ $p['address'] }}</div>
                                </div>
                                <span class="w-16 shrink-0 text-right text-xs text-slate-500">{{ $p['leg_km'] !== null ? '+'.number_format($p['leg_km'], 1).' km' : 'sin GPS' }}</span>
                                <div class="flex shrink-0 items-center">
                                    <button wire:click="move({{ $i }}, -1)" @disabled($i === 0) class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" title="Subir"><x-heroicon-m-chevron-up class="h-4 w-4" /></button>
                                    <button wire:click="move({{ $i }}, 1)" @disabled($loop->last) class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" title="Bajar"><x-heroicon-m-chevron-down class="h-4 w-4" /></button>
                                    <button wire:click="removeFromPlan({{ $i }})" class="rounded p-1 text-slate-400 hover:bg-danger-50 hover:text-danger-600" title="Quitar"><x-heroicon-m-x-mark class="h-4 w-4" /></button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @elseif ($preview)
                <x-ui.card :title="'Paradas · '.$preview->user?->name" :subtitle="\App\Support\Catalog::label('route_status', $preview->status)" :padding="false">
                    <ol class="divide-y divide-slate-100">
                        @foreach ($previewPoints as $p)
                            <li class="flex items-center gap-3 px-5 py-2.5">
                                <span @class(['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold', 'bg-success-600 text-white' => $p['stop_status'] === 'done', 'bg-warning-500 text-white' => $p['stop_status'] === 'arrived', 'bg-slate-100 text-slate-700' => ! in_array($p['stop_status'], ['done', 'arrived'])])>{{ $p['sequence'] }}</span>
                                <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-slate-800">{{ $p['title'] }}</div><div class="truncate text-xs text-slate-500">{{ $p['subtitle'] }}</div></div>
                                <x-ui.status catalog="route_stop_status" :value="$p['stop_status']" size="xs" />
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @else
                <div class="flex items-center gap-3 rounded-xl border border-brand-100 bg-brand-50/60 p-4 text-sm text-slate-600">
                    <x-heroicon-o-sparkles class="h-6 w-6 shrink-0 text-brand-600" />
                    <div>Selecciona {{ $type === 'tecnico' ? 'el técnico' : 'el cobrador' }} y las actividades, luego pulsa <b>Ruta sugerida</b>. El orden parte de su última ubicación (o del centro de la zona) y prioriza lo urgente.</div>
                </div>
            @endif

            <x-ui.card :title="'Rutas del '.\Carbon\Carbon::parse($date)->translatedFormat('d \d\e F Y')" :padding="false">
                <x-ui.table>
                    <x-slot:head><th>Responsable</th><th>Tipo</th><th>Zona</th><th>Avance</th><th>Distancia</th><th>Estado</th><th></th></x-slot:head>
                    @forelse ($routes as $r)
                        @php $done = $r->stops->where('status', 'done')->count(); $total = $r->stops->count(); @endphp
                        <tr wire:key="r{{ $r->id }}" @class(['bg-brand-50/50' => $previewRouteId === $r->id])>
                            <td><div class="flex items-center gap-2"><x-ui.avatar :name="$r->user?->name" size="sm" :color="$r->type === 'tecnico' ? 'brand' : 'purple'" /><span class="font-medium">{{ $r->user?->name }}</span></div></td>
                            <td><x-ui.badge :color="$r->type === 'tecnico' ? 'brand' : 'purple'" size="xs">{{ $r->type === 'tecnico' ? 'Técnico' : 'Cobranza' }}</x-ui.badge></td>
                            <td class="text-xs">{{ $r->zone?->name ?? 'Varias' }}</td>
                            <td class="w-40"><x-ui.progress :value="$total ? $done / $total * 100 : 0" color="brand" height="h-1.5" /><div class="text-2xs text-slate-500">{{ $done }}/{{ $total }} paradas</div></td>
                            <td class="text-xs">{{ $r->distance_km ? number_format($r->distance_km, 1).' km' : '—' }}</td>
                            <td><x-ui.status catalog="route_status" :value="$r->status" size="xs" /></td>
                            <td class="text-right whitespace-nowrap">
                                <button wire:click="preview({{ $r->id }})" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-brand-600" title="Ver en el mapa"><x-heroicon-o-eye class="h-4 w-4" /></button>
                                @if ($r->mapsUrl())<a href="{{ $r->mapsUrl() }}" target="_blank" class="inline-block rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-brand-600" title="Google Maps"><x-heroicon-o-map class="h-4 w-4" /></a>@endif
                                @if (Route::has('staff.show'))<a href="{{ route('staff.show', $r->user_id) }}" wire:navigate class="inline-block rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-brand-600" title="Ver responsable"><x-heroicon-o-user class="h-4 w-4" /></a>@endif
                                <button wire:click="deleteRoute({{ $r->id }})" wire:confirm="¿Eliminar esta ruta y sus paradas?" class="rounded-md p-1 text-slate-400 hover:bg-danger-50 hover:text-danger-600" title="Eliminar"><x-heroicon-o-trash class="h-4 w-4" /></button>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="7" icon="truck" title="Sin rutas para esta fecha" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>
</div>
