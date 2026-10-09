<div x-data="{ busy: null, gps(method) { this.busy = method; const run = (lat, lng) => this.$wire.call(method, lat, lng).finally(() => this.busy = null); if (!navigator.geolocation) return run(null, null); navigator.geolocation.getCurrentPosition((p) => run(p.coords.latitude, p.coords.longitude), () => run(null, null), { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }); } }">
    <x-ui.page-header :title="'Ticket '.$t->folio" :subtitle="$t->subject" :breadcrumbs="['Tickets' => route('tickets.index'), $t->folio => null]">
        <x-ui.button variant="secondary" icon="document-arrow-down" :href="route('documents.ticket', $t)" :navigate="false" target="_blank">Evidencia de servicio (PDF)</x-ui.button>
        @if ($canWork)
            <x-ui.dropdown>
                <x-slot:trigger><x-ui.button variant="secondary" icon="ellipsis-horizontal">Acciones</x-ui.button></x-slot:trigger>
                @can('tickets.manage')<x-ui.dropdown-item icon="pencil-square" wire:click="openEdit">Editar ticket</x-ui.dropdown-item>@endcan
                @if ($t->isOpen())
                    @if ($t->client)<x-ui.dropdown-item icon="truck" @click="gps('onWay')">Avisar «técnico en camino»</x-ui.dropdown-item>@endif
                    <x-ui.dropdown-item icon="map-pin" @click="gps('arriveHere')">Registrar llegada</x-ui.dropdown-item>
                    <x-ui.dropdown-item icon="arrow-right-start-on-rectangle" @click="gps('departHere')">Registrar salida</x-ui.dropdown-item>
                @endif
                @if (Route::has('field.work') && auth()->user()->can('field.work') && (int) $t->technician_id === auth()->id())
                    <x-ui.dropdown-item icon="device-phone-mobile" :href="route('field.work', $t)">Abrir en app de campo</x-ui.dropdown-item>
                @endif
            </x-ui.dropdown>
            <x-ui.button icon="arrow-path" wire:click="openStatus">Cambiar estado</x-ui.button>
        @endif
    </x-ui.page-header>

    {{-- Encabezado --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card flex flex-col justify-center gap-1.5 p-4">
            <div class="text-xs text-slate-500">Estado</div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.status catalog="ticket_status" :value="$t->status" />
                <x-ui.status catalog="ticket_priorities" :value="$t->priority" :dot="false" />
            </div>
            <div class="text-xs text-slate-500">{{ config('wisp.ticket_categories.'.$t->category, $t->category) }}</div>
        </div>

        {{-- SLA con cuenta regresiva --}}
        @php
            $state = $t->slaState();
            $total = $t->sla_due_at ? max(1, $t->created_at->diffInSeconds($t->sla_due_at)) : 1;
            $elapsedPct = $t->sla_due_at ? min(100, round($t->created_at->diffInSeconds($t->isOpen() ? now() : ($t->resolved_at ?? now())) / $total * 100)) : 0;
        @endphp
        <div class="card p-4"
             x-data="{ due: {{ $t->sla_due_at && $t->isOpen() ? $t->sla_due_at->getTimestamp() * 1000 : 'null' }}, now: Date.now(),
                      fmt(ms) { const s = Math.floor(Math.abs(ms) / 1000); const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (d ? d + 'd ' : '') + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0'); } }"
             x-init="if (due) setInterval(() => now = Date.now(), 1000)">
            <div class="flex items-center justify-between text-xs text-slate-500"><span>SLA · objetivo {{ $t->sla_due_at?->format('d/m H:i') ?? '—' }}</span>
                @include('livewire.tickets.partials.sla', ['ticket' => $t])</div>
            @if ($t->sla_due_at && $t->isOpen())
                <div class="mt-1 font-mono text-2xl font-bold" :class="due - now < 0 ? 'text-danger-600' : '{{ $state === 'risk' ? 'text-warning-600' : 'text-navy-900' }}'">
                    <span x-text="(due - now < 0 ? '-' : '') + fmt(due - now)"></span>
                </div>
                <div class="text-[11px] text-slate-500" x-text="due - now < 0 ? 'Tiempo excedido' : 'Tiempo restante'"></div>
            @else
                <div class="mt-1 text-2xl font-bold {{ $t->sla_breached ? 'text-danger-600' : 'text-success-600' }}">{{ $t->resolved_at ? \App\Livewire\Tickets\Index::duration($t->created_at->diffInMinutes($t->resolved_at)) : '—' }}</div>
                <div class="text-[11px] text-slate-500">Tiempo de resolución</div>
            @endif
            <x-ui.progress :value="$elapsedPct" :color="$state === 'breached' ? 'danger' : ($state === 'risk' ? 'warning' : 'success')" class="mt-2" />
        </div>

        <x-ui.stat variant="soft" label="Técnico" :value="$t->technician?->name ?? 'Sin asignar'" :sub="$t->on_way_at && ! $t->arrived_at ? 'En camino desde '.$t->on_way_at->format('H:i') : ($t->arrived_at ? 'Llegó '.$t->arrived_at->format('d/m H:i').($t->departed_at ? ' · salió '.$t->departed_at->format('H:i') : '') : 'Sin visita registrada')" icon="wrench-screwdriver" :color="$t->technician ? 'brand' : 'slate'" />
        <x-ui.stat variant="soft" label="Creado" :value="$t->created_at->format('d/m/Y H:i')" :sub="$t->created_at->diffForHumans().' · '.($t->createdBy?->name ?? (['cliente' => 'Cliente', 'whatsapp' => 'WhatsApp', 'telefono' => 'Teléfono', 'automatico' => 'Automático'][$t->source] ?? 'Administración'))" icon="calendar" color="purple" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="min-w-0 space-y-5 xl:col-span-2">
            <x-ui.card title="Descripción">
                <p class="text-sm whitespace-pre-line text-slate-700">{{ $t->description ?: 'Sin descripción.' }}</p>
                @if ($t->resolution)
                    <div class="mt-4 rounded-lg border border-success-100 bg-success-50 p-3 text-sm text-success-800">
                        <div class="text-xs font-semibold tracking-wide text-success-700 uppercase">Resolución</div>{{ $t->resolution }}
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Evidencias fotográficas" subtitle="Antes, durante, después, equipo e instalación — con fecha, hora y GPS">
                @include('livewire.tickets.partials.evidence', ['compact' => false])
            </x-ui.card>

            <x-ui.card title="Historial y comentarios">
                @if ($canWork)
                    <form wire:submit="addComment" class="mb-5">
                        <x-ui.textarea wire:model="commentBody" rows="2" placeholder="Escribe un comentario o avance..." />
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <x-ui.toggle wire:model="commentInternal" label="Nota interna" description="No visible para el cliente" />
                            <x-ui.button type="submit" icon="paper-airplane" wire:loading.attr="disabled" wire:target="addComment">Comentar</x-ui.button>
                        </div>
                    </form>
                @endif
                <ol class="relative space-y-4 border-l-2 border-slate-100 pl-6">
                    @foreach ($timeline as $c)
                        @php
                            [$icon, $bg] = match ($c->type) {
                                'status' => ['arrow-path', 'bg-purple-600'],
                                'assignment' => ['user-plus', 'bg-teal-600'],
                                'system' => ['cog-6-tooth', 'bg-slate-400'],
                                default => ['chat-bubble-left-ellipsis', $c->internal ? 'bg-warning-600' : 'bg-brand-600'],
                            };
                        @endphp
                        <li class="relative" wire:key="c{{ $c->id }}">
                            <span class="absolute top-0 -left-[35px] flex h-6 w-6 items-center justify-center rounded-full {{ $bg }} text-white ring-4 ring-white">
                                <x-dynamic-component :component="'heroicon-s-'.$icon" class="h-3.5 w-3.5" />
                            </span>
                            <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                <span class="font-semibold text-slate-700">{{ $c->user?->name ?? 'Sistema' }}</span>
                                <span>{{ $c->created_at->format('d/m/Y H:i') }}</span>
                                @if ($c->internal)<x-ui.badge color="warning" size="xs">Interno</x-ui.badge>@endif
                                @if (! empty($c->meta['lat']))<a class="link" target="_blank" href="https://www.google.com/maps?q={{ $c->meta['lat'] }},{{ $c->meta['lng'] }}">ver GPS</a>@endif
                            </div>
                            <div @class(['mt-0.5 text-sm whitespace-pre-line', 'rounded-lg bg-slate-50 p-2.5 text-slate-800' => $c->type === 'comment', 'text-slate-600' => $c->type !== 'comment'])>{{ $c->body }}</div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            @if ($t->client)
                <x-ui.card title="Cliente">
                    <x-slot:actions><x-ui.button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="route('clients.show', $t->client)" /></x-slot:actions>
                    <div class="flex items-center gap-3">
                        <x-ui.avatar :name="$t->client->full_name" size="lg" />
                        <div class="min-w-0">
                            <a href="{{ route('clients.show', $t->client) }}" wire:navigate class="block truncate font-semibold text-slate-800 hover:text-brand-600">{{ $t->client->full_name }}</a>
                            <div class="text-xs text-slate-500">#{{ $t->client->code }} · {{ $t->client->plan?->name }}</div>
                            <x-ui.status catalog="client_status" :value="$t->client->service_status" size="xs" class="mt-1" />
                        </div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3">
                        <x-ui.detail label="Teléfono">@if ($t->client->phone)<a class="link" href="tel:{{ $t->client->phone }}">{{ $t->client->phone }}</a>@endif</x-ui.detail>
                        <x-ui.detail label="Zona">{{ $t->client->zone?->name }}</x-ui.detail>
                        <x-ui.detail label="Dirección" class="col-span-2">{{ $t->client->address }}{{ $t->client->colonia ? ', '.$t->client->colonia : '' }}</x-ui.detail>
                        <x-ui.detail label="Repetidor">{{ $t->client->repeater?->code }}</x-ui.detail>
                        <x-ui.detail label="Señal">{{ $t->client->signal_dbm ? $t->client->signal_dbm.' dBm' : '' }}</x-ui.detail>
                        <x-ui.detail label="IP">{{ $t->client->ip_address }}</x-ui.detail>
                        <x-ui.detail label="Conexión"><span class="{{ $t->client->online ? 'text-success-600' : 'text-slate-500' }}">{{ $t->client->online ? 'En línea' : 'Desconectado' }}</span></x-ui.detail>
                    </dl>
                    @if ($t->client->lat)
                        <a href="https://www.google.com/maps/dir/?api=1&destination={{ $t->client->lat }},{{ $t->client->lng }}" target="_blank" class="mt-3 flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-50"><x-heroicon-o-map class="h-4 w-4" /> Navegar al domicilio</a>
                    @endif
                </x-ui.card>
            @endif

            @if ($t->repeater && (! $t->client || $outage))
                <x-ui.card title="Infraestructura relacionada">
                    @if ($outage && ! $outage->ended_at)
                        <div class="mb-3 rounded-lg bg-danger-600 p-3 text-white">
                            <div class="flex items-center gap-2 text-sm font-bold"><x-heroicon-s-exclamation-triangle class="h-5 w-5" /> REPETIDOR {{ $t->repeater->code }} FUERA DE SERVICIO</div>
                            <div class="mt-1 text-xs text-white/90">Desde {{ $outage->started_at->format('d/m H:i') }} ({{ $outage->started_at->diffForHumans() }}) · {{ $outage->affected_clients ?: $affected }} clientes afectados</div>
                        </div>
                    @endif
                    <dl class="grid grid-cols-2 gap-3">
                        <x-ui.detail label="Repetidor">
                            @if (Route::has('network.repeaters.show'))<a class="link" href="{{ route('network.repeaters.show', $t->repeater) }}" wire:navigate>{{ $t->repeater->code }} · {{ $t->repeater->name }}</a>@else{{ $t->repeater->code }} · {{ $t->repeater->name }}@endif
                        </x-ui.detail>
                        <x-ui.detail label="Estado"><x-ui.status catalog="device_status" :value="$t->repeater->status" size="xs" /></x-ui.detail>
                        <x-ui.detail label="Torre">{{ $t->repeater->tower?->name }}</x-ui.detail>
                        <x-ui.detail label="Zona">{{ $t->repeater->zone?->name }}</x-ui.detail>
                        @if ($affected !== null)<x-ui.detail label="Clientes en el repetidor">{{ number_format($affected) }}</x-ui.detail>@endif
                        <x-ui.detail label="Última comunicación">{{ $t->repeater->last_seen_at?->diffForHumans() }}</x-ui.detail>
                    </dl>
                    @if ($t->repeater->lat)
                        <a href="https://www.google.com/maps/dir/?api=1&destination={{ $t->repeater->lat }},{{ $t->repeater->lng }}" target="_blank" class="mt-3 flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-50"><x-heroicon-o-map class="h-4 w-4" /> Navegar al repetidor</a>
                    @endif
                </x-ui.card>
            @endif

            <x-ui.card title="Asignación">
                @can('tickets.manage')
                    <div class="flex gap-2">
                        <x-ui.select wire:model="assignTo" placeholder="Selecciona técnico..." class="flex-1">
                            @foreach ($technicians as $id => $name)
                                <option value="{{ $id }}">{{ $name }} — {{ $workload[$id] ?? 0 }} abiertos</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.button icon="user-plus" wire:click="assign">Asignar</x-ui.button>
                    </div>
                    @error('assignTo')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-slate-500">Se notifica al cliente por WhatsApp con el nombre del técnico.</p>
                @else
                    <p class="text-sm text-slate-700">{{ $t->technician?->name ?? 'Sin asignar' }}</p>
                @endcan
            </x-ui.card>

            <x-ui.card title="Materiales utilizados">
                @include('livewire.tickets.partials.materials')
            </x-ui.card>

            <x-ui.card title="Firma del cliente">
                @include('livewire.tickets.partials.signature')
            </x-ui.card>

            @if ($related->isNotEmpty())
                <x-ui.card :title="$t->client_id ? 'Otros tickets del cliente' : 'Tickets abiertos del repetidor'">
                    @foreach ($related as $r)
                        <a href="{{ route('tickets.show', $r) }}" wire:navigate class="flex items-center justify-between gap-2 border-b border-slate-100 py-2 text-sm last:border-0 hover:text-brand-600">
                            <span class="min-w-0"><span class="font-semibold">{{ $r->folio }}</span> <span class="block truncate text-xs text-slate-500">{{ $r->subject }} · {{ $r->created_at->format('d/m/Y') }}</span></span>
                            <x-ui.status catalog="ticket_status" :value="$r->status" size="xs" />
                        </a>
                    @endforeach
                </x-ui.card>
            @endif
        </div>
    </div>

    {{-- ============================== MODALES ============================== --}}
    <x-ui.modal wire:model="showStatus" title="Cambiar estado" size="md">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-2">
                @foreach (config('wisp.ticket_status') as $k => [$label, $color])
                    <label @class(['flex cursor-pointer items-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold transition', 'border-brand-600 bg-brand-50 text-brand-700' => $newStatus === $k, 'border-slate-200 text-slate-600 hover:border-slate-300' => $newStatus !== $k])>
                        <input type="radio" wire:model.live="newStatus" value="{{ $k }}" class="sr-only">
                        <x-ui.badge :color="$color" dot size="xs">{{ $label }}</x-ui.badge>
                        @if ($t->status === $k)<span class="ml-auto text-[10px] font-normal text-slate-400">actual</span>@endif
                    </label>
                @endforeach
            </div>
            <x-ui.textarea :label="$newStatus === 'resuelto' ? 'Solución aplicada' : 'Nota (opcional)'" wire:model="statusNote" rows="3" placeholder="{{ $newStatus === 'resuelto' ? 'Describe qué se hizo para resolver la falla' : 'Motivo del cambio' }}" />
            @error('newStatus')<p class="text-xs text-danger-600">{{ $message }}</p>@enderror
            @if (in_array($newStatus, ['en_proceso', 'resuelto', 'cerrado']))
                <p class="text-xs text-slate-500">Se notificará al cliente sobre la actualización.</p>
            @endif
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="updateStatus">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showEdit" title="Editar ticket" size="lg">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Asunto" wire:model="edit.subject" class="sm:col-span-2" required />
            <x-ui.select label="Categoría" wire:model="edit.category" :options="config('wisp.ticket_categories')" />
            <x-ui.select label="Prioridad" wire:model="edit.priority" :options="config('wisp.ticket_priorities')" hint="Cambiar la prioridad recalcula el SLA." />
            <x-ui.textarea label="Descripción" wire:model="edit.description" rows="4" class="sm:col-span-2" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveEdit">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
