@php
    $c = $t->client;
    $arrived = $t->arrived_at && ! $t->departed_at;
    $statusOptions = ['en_proceso' => ['En proceso', 'warning'], 'en_espera' => ['En espera', 'slate'], 'resuelto' => ['Resuelto', 'success']];
@endphp
<div class="mx-auto max-w-2xl"
     x-data="{ busy: null, resolving: false,
               gps(method) {
                   this.busy = method;
                   const run = (lat, lng) => this.$wire.call(method, lat, lng).finally(() => this.busy = null);
                   wispGeo.get().then((c) => run(c.lat, c.lng)).catch((e) => { wispGeo.warn(e); run(null, null); });
               } }">

    <a href="{{ route('field.today') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-brand-600">
        <x-heroicon-m-arrow-left class="h-4 w-4" /> Mi jornada
    </a>

    <x-ui.geo-permission />

    {{-- Encabezado --}}
    <div class="relative mb-4 overflow-hidden rounded-2xl bg-navy-900 p-5 text-white shadow-lg">
        <div class="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-brand-600/30"></div>
        <div class="relative">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="rounded-md bg-white/15 px-2 py-0.5 text-xs font-bold">{{ $t->folio }}</span>
                <x-ui.status catalog="ticket_status" :value="$t->status" size="xs" />
                <x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" :dot="false" />
                @include('livewire.tickets.partials.sla', ['ticket' => $t])
            </div>
            <h1 class="mt-2 text-xl leading-tight font-bold">{{ $t->subject }}</h1>
            <div class="mt-0.5 text-sm text-navy-200">{{ config('wisp.ticket_categories.'.$t->category, $t->category) }}</div>
            @if ($t->description)
                <p class="mt-3 rounded-xl bg-white/10 p-3 text-sm whitespace-pre-line text-navy-50">{{ $t->description }}</p>
            @endif
        </div>
    </div>

    {{-- Cliente / sitio --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
        @if ($c)
            <div class="flex items-start gap-3">
                <x-ui.avatar :name="$c->full_name" size="lg" />
                <div class="min-w-0 flex-1">
                    <div class="text-base leading-tight font-bold text-navy-900">{{ $c->full_name }}</div>
                    <div class="text-xs text-slate-500">#{{ $c->code }} · {{ $c->plan?->name }}</div>
                    <x-ui.status catalog="client_status" :value="$c->service_status" size="xs" class="mt-1" />
                </div>
                @if ($c->phone)
                    <a href="tel:{{ $c->phone }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600" title="Llamar"><x-heroicon-s-phone class="h-5 w-5" /></a>
                @endif
            </div>
            <div class="mt-3 flex items-start gap-1.5 text-sm text-slate-600">
                <x-heroicon-m-map-pin class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                <span>{{ $c->address }}{{ $c->colonia ? ', '.$c->colonia : '' }}@if ($c->references)<span class="block text-xs text-slate-500">Ref.: {{ $c->references }}</span>@endif</span>
            </div>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Repetidor</dt><dd class="font-semibold text-slate-800">{{ $c->repeater?->code ?? '—' }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Señal</dt><dd class="font-semibold text-slate-800">{{ $c->signal_dbm ? $c->signal_dbm.' dBm' : '—' }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-2"><dt class="text-slate-500">Conexión</dt><dd class="font-semibold {{ $c->online ? 'text-success-600' : 'text-slate-500' }}">{{ $c->online ? 'En línea' : 'Sin conexión' }}</dd></div>
            </dl>
        @elseif ($t->repeater)
            <div class="text-xs text-slate-500">Infraestructura</div>
            <div class="text-base font-bold text-navy-900">{{ $t->repeater->code }} · {{ $t->repeater->name }}</div>
            <div class="text-sm text-slate-600">{{ $t->repeater->zone?->name }}</div>
        @endif

        @if ($t->isOpen())
            <div class="mt-4 grid grid-cols-2 gap-2">
                @if ($nav)
                    <a href="{{ $nav }}" target="_blank" class="flex items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white py-3 text-sm font-semibold text-slate-700 active:scale-[.98]"><x-heroicon-o-map class="h-5 w-5 text-brand-600" /> Navegar</a>
                @else
                    <span class="flex items-center justify-center rounded-xl border border-dashed border-slate-300 py-3 text-xs text-slate-400">Sin coordenadas</span>
                @endif

                @if (! $arrived)
                    @if ($c && ! $t->on_way_at)
                        <button type="button" @click="gps('onWay')" :disabled="busy" class="flex items-center justify-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 py-3 text-sm font-semibold text-brand-700 active:scale-[.98]">
                            <x-heroicon-o-truck class="h-5 w-5" /> <span x-text="busy === 'onWay' ? 'Avisando...' : 'En camino'"></span>
                        </button>
                    @endif
                    <button type="button" @click="gps('arriveHere')" :disabled="busy" @class(['flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]', 'col-span-2' => $c && ! $t->on_way_at])>
                        <x-heroicon-o-map-pin class="h-5 w-5" /> <span x-text="busy === 'arriveHere' ? 'Obteniendo GPS...' : 'Llegué'"></span>
                    </button>
                @else
                    <button type="button" @click="gps('departHere')" :disabled="busy" class="flex items-center justify-center gap-1.5 rounded-xl bg-success-600 py-3 text-sm font-bold text-white shadow-sm active:scale-[.98]">
                        <x-heroicon-o-arrow-right-start-on-rectangle class="h-5 w-5" /> <span x-text="busy === 'departHere' ? 'Registrando...' : 'Salí · terminar'"></span>
                    </button>
                @endif
            </div>
            @if ($t->on_way_at || $t->arrived_at)
                <div class="mt-2 text-xs text-slate-500">
                    {{ $t->on_way_at ? 'En camino '.$t->on_way_at->format('H:i') : '' }}{{ $t->on_way_at && $t->arrived_at ? ' · ' : '' }}{{ $t->arrived_at ? 'Llegada '.$t->arrived_at->format('H:i') : '' }}{{ $t->departed_at ? ' · salida '.$t->departed_at->format('H:i') : '' }}
                </div>
            @endif
        @endif
    </div>

    {{-- Estado --}}
    @if ($t->isOpen())
        <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
            <div class="mb-2 text-sm font-bold text-navy-900">Estado del trabajo</div>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($statusOptions as $value => [$label, $color])
                    @if ($value === 'resuelto')
                        <button type="button" @click="resolving = !resolving" @class(['rounded-xl py-2.5 text-sm font-semibold ring-1 active:scale-[.98]', 'bg-success-600 text-white ring-success-600' => $t->status === $value, 'bg-white text-success-700 ring-success-200' => $t->status !== $value])>{{ $label }}</button>
                    @else
                        <button type="button" wire:click="updateStatus('{{ $value }}')" wire:loading.attr="disabled" @class(['rounded-xl py-2.5 text-sm font-semibold ring-1 active:scale-[.98]', 'bg-brand-600 text-white ring-brand-600' => $t->status === $value, 'bg-white text-slate-700 ring-slate-200' => $t->status !== $value])>{{ $label }}</button>
                    @endif
                @endforeach
            </div>
            <div x-show="resolving" x-cloak x-collapse class="mt-3 space-y-2">
                <x-ui.textarea wire:model="statusNote" rows="3" maxlength="190" placeholder="Describe la solución aplicada (mínimo 5 caracteres)" />
                <x-ui.button variant="success" icon="check" class="w-full" wire:click="updateStatus('resuelto')" wire:loading.attr="disabled">Marcar como resuelto</x-ui.button>
            </div>
            @error('newStatus')<p class="mt-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
        </div>
    @endif

    {{-- Evidencias --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
        <div class="mb-3 text-sm font-bold text-navy-900">Evidencias fotográficas</div>
        @include('livewire.tickets.partials.evidence', ['compact' => true])
    </div>

    {{-- Materiales --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
        <div class="mb-3 text-sm font-bold text-navy-900">Materiales usados</div>
        @include('livewire.tickets.partials.materials')
    </div>

    {{-- Comentarios --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
        <div class="mb-3 text-sm font-bold text-navy-900">Comentarios e historial</div>
        <form wire:submit="addComment" class="mb-4">
            <x-ui.textarea wire:model="commentBody" rows="2" maxlength="2000" placeholder="Escribe un avance o comentario..." />
            <div class="mt-2 flex items-center justify-between gap-3">
                <x-ui.toggle wire:model="commentInternal" label="Nota interna" />
                <x-ui.button type="submit" size="sm" icon="paper-airplane" wire:loading.attr="disabled" wire:target="addComment">Comentar</x-ui.button>
            </div>
        </form>
        <ol class="space-y-3">
            @foreach ($timeline as $cm)
                <li wire:key="cm{{ $cm->id }}" class="text-sm">
                    <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                        <span class="font-semibold text-slate-700">{{ $cm->user?->name ?? 'Sistema' }}</span>
                        <span>{{ $cm->created_at->format('d/m H:i') }}</span>
                        @if ($cm->internal)<x-ui.badge color="warning" size="xs">Interno</x-ui.badge>@endif
                    </div>
                    <div @class(['mt-0.5 whitespace-pre-line', 'rounded-lg bg-slate-50 p-2.5 text-slate-800' => $cm->type === 'comment', 'text-slate-600' => $cm->type !== 'comment'])>{{ $cm->body }}</div>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Firma --}}
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
        <div class="mb-3 text-sm font-bold text-navy-900">Firma de conformidad</div>
        @include('livewire.tickets.partials.signature')
    </div>
</div>
