<div class="mx-auto max-w-3xl">
    <x-ui.geo-permission />

    {{-- Encabezado --}}
    <div class="mb-4 flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium text-slate-500">{{ ucfirst(now()->translatedFormat('l d \d\e F')) }}</p>
            <h1 class="truncate text-2xl font-bold tracking-tight text-navy-900">Mi cobranza</h1>
            <p class="text-sm text-slate-500">Hola {{ str(auth()->user()->name)->before(' ') }} · {{ $summary['pending'] }} clientes por visitar</p>
        </div>
        <div x-data="geo('myLat', 'myLng')" x-init="if (!$wire.myLat) locate()" class="shrink-0 text-right">
            <button type="button" @click="locate()" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50">
                <x-heroicon-o-map-pin class="h-4 w-4 text-brand-600" />
                <span x-text="locating ? 'Ubicando…' : ($wire.myLat ? 'Reordenar' : 'Ubicarme')">Ubicarme</span>
            </button>
            <p x-show="error" x-text="error" class="mt-1 max-w-[150px] text-[10px] text-danger-600"></p>
        </div>
    </div>

    {{-- Resumen del día --}}
    <div class="mb-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <div class="rounded-xl bg-success-600 p-3 text-white shadow-sm">
            <div class="text-[11px] font-medium text-white/85">Cobrado hoy</div>
            <div class="text-xl font-bold">{{ money($summary['collected'], false) }}</div>
            <div class="text-[11px] text-white/80">{{ $summary['payments'] }} {{ $summary['payments'] === 1 ? 'pago' : 'pagos' }}</div>
        </div>
        <div class="rounded-xl bg-purple-600 p-3 text-white shadow-sm">
            <div class="text-[11px] font-medium text-white/85">Mi corte abierto</div>
            <div class="text-xl font-bold">{{ money($cut?->expected_amount ?? 0, false) }}</div>
            <div class="text-[11px] text-white/80">{{ $cut ? $cut->payments_count.' cobros · '.$cut->folio : 'Sin efectivo por entregar' }}</div>
        </div>
        <div class="card p-3">
            <div class="text-[11px] font-medium text-slate-500">Visitas hoy</div>
            <div class="text-xl font-bold text-navy-900">{{ $summary['visits'] }}</div>
        </div>
        <div class="card p-3">
            <div class="text-[11px] font-medium text-slate-500">Por cobrar</div>
            <div class="text-xl font-bold text-danger-600">{{ money($summary['pending_amount'], false) }}</div>
        </div>
    </div>

    @if ($lastPayment)
        <div class="mb-4 flex items-center gap-3 rounded-xl border border-success-100 bg-success-50 p-3">
            <x-heroicon-s-check-circle class="h-7 w-7 shrink-0 text-success-600" />
            <div class="min-w-0 flex-1 text-sm">
                <div class="font-semibold text-success-700">Pago {{ $lastPayment->folio }} registrado</div>
                <div class="text-xs text-slate-600">{{ money($lastPayment->amount) }} · se envió confirmación al cliente</div>
            </div>
            <a href="{{ route('documents.receipt', $lastPayment) }}" target="_blank" class="rounded-lg bg-white px-3 py-2 text-xs font-semibold text-success-700 shadow-xs">Comprobante</a>
        </div>
    @endif

    {{-- Pestañas + búsqueda --}}
    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
        <div class="flex rounded-xl bg-slate-200/70 p-1 text-sm font-semibold">
            <button type="button" wire:click="$set('view', 'pendientes')" @class(['flex-1 rounded-lg px-3 py-1.5 transition sm:flex-none', 'bg-white text-navy-900 shadow-xs' => $view === 'pendientes', 'text-slate-600' => $view !== 'pendientes'])>Por visitar <span class="opacity-60">{{ $summary['pending'] }}</span></button>
            <button type="button" wire:click="$set('view', 'visitados')" @class(['flex-1 rounded-lg px-3 py-1.5 transition sm:flex-none', 'bg-white text-navy-900 shadow-xs' => $view === 'visitados', 'text-slate-600' => $view !== 'visitados'])>Visitados hoy <span class="opacity-60">{{ $visitsToday->count() }}</span></button>
        </div>
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar cliente..." class="flex-1" />
    </div>

    @if ($route && $view === 'pendientes')
        <div class="mb-3 flex items-center justify-between gap-2 rounded-xl bg-brand-50 px-3 py-2 text-xs text-brand-700">
            <span class="flex items-center gap-1.5"><x-heroicon-o-truck class="h-4 w-4" /> Ruta del día: {{ $route->stops->count() }} paradas ({{ $route->stops->where('status', 'done')->count() }} hechas)</span>
            @if ($route->mapsUrl())<a href="{{ $route->mapsUrl() }}" target="_blank" class="font-semibold underline">Abrir ruta</a>@endif
        </div>
    @elseif ($view === 'pendientes' && $myLat)
        <p class="mb-3 text-xs text-slate-500"><x-heroicon-o-map-pin class="inline h-3.5 w-3.5" /> Ordenados por cercanía desde tu ubicación.</p>
    @endif

    {{-- Lista de clientes --}}
    <div class="space-y-3">
        @forelse ($clients as $c)
            @php
                $visit = $visitsToday[$c->id] ?? null;
                $days = $c->oldest_due && \Carbon\Carbon::parse($c->oldest_due)->lt(today()) ? (int) \Carbon\Carbon::parse($c->oldest_due)->diffInDays(today()) : 0;
                $nav = $c->lat && $c->lng ? 'https://www.google.com/maps/dir/?api=1&destination='.$c->lat.','.$c->lng : 'https://www.google.com/maps/search/?api=1&query='.urlencode(trim($c->address.' '.$c->colonia.' '.$c->community));
            @endphp
            <div wire:key="fc{{ $c->id }}" class="card overflow-hidden">
                <div class="p-4">
                    <div class="flex items-start gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-navy-900 text-xs font-bold text-white">
                            {{ $view === 'pendientes' ? $loop->iteration : '✓' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-slate-800">{{ $c->full_name }}</div>
                                    <div class="text-xs text-slate-500">#{{ $c->code }} · {{ $c->plan?->name ?? 'Sin plan' }}</div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-lg leading-tight font-bold {{ $c->balance > 0 ? 'text-danger-600' : 'text-success-700' }}">{{ money($c->balance) }}</div>
                                    @if ($days > 0)<div class="text-[11px] font-semibold text-danger-600">{{ $days }} {{ $days === 1 ? 'día' : 'días' }} de atraso</div>@endif
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <x-ui.status catalog="client_status" :value="$c->service_status" size="xs" />
                                @if ($c->zone)<x-ui.badge size="xs">{{ $c->zone->name }}</x-ui.badge>@endif
                                @if (isset($c->route_seq))<x-ui.badge color="brand" size="xs">Ruta #{{ $c->route_seq }}</x-ui.badge>@endif
                                @if (isset($c->distance_km))<span class="text-[11px] text-slate-400">{{ $c->distance_km < 1 ? round($c->distance_km * 1000).' m' : number_format($c->distance_km, 1).' km' }}</span>@endif
                                @if ($visit)
                                    <x-ui.status catalog="visit_results" :value="$visit->result" size="xs" />
                                    @if ($visit->payment)<span class="text-[11px] text-success-700">{{ $visit->payment->folio }}</span>@endif
                                @endif
                            </div>
                            <p class="mt-2 flex items-start gap-1 text-xs text-slate-600">
                                <x-heroicon-o-home class="mt-px h-3.5 w-3.5 shrink-0 text-slate-400" />
                                <span>{{ $c->address }}@if ($c->colonia), {{ $c->colonia }}@endif @if ($c->references)<span class="text-slate-400"> · {{ $c->references }}</span>@endif</span>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-4 divide-x divide-slate-100 border-t border-slate-100 text-xs font-semibold">
                    <button type="button" wire:click="openPay({{ $c->id }})" class="flex flex-col items-center gap-1 py-3 text-success-700 hover:bg-success-50 active:bg-success-50">
                        <x-heroicon-o-banknotes class="h-5 w-5" /> Cobrar
                    </button>
                    <button type="button" wire:click="openVisit({{ $c->id }})" class="flex flex-col items-center gap-1 py-3 text-slate-700 hover:bg-slate-50">
                        <x-heroicon-o-clipboard-document-list class="h-5 w-5" /> Visita
                    </button>
                    <a href="{{ $nav }}" target="_blank" class="flex flex-col items-center gap-1 py-3 text-brand-700 hover:bg-brand-50">
                        <x-heroicon-o-map class="h-5 w-5" /> Navegar
                    </a>
                    @if ($c->phone || $c->whatsapp)
                        <a href="tel:{{ preg_replace('/\D+/', '', $c->phone ?: $c->whatsapp) }}" class="flex flex-col items-center gap-1 py-3 text-slate-700 hover:bg-slate-50">
                            <x-heroicon-o-phone class="h-5 w-5" /> Llamar
                        </a>
                    @else
                        <span class="flex flex-col items-center gap-1 py-3 text-slate-300"><x-heroicon-o-phone class="h-5 w-5" /> Llamar</span>
                    @endif
                </div>

                <button type="button" wire:click="toggle({{ $c->id }})" class="flex w-full items-center justify-center gap-1 border-t border-slate-100 py-2 text-[11px] font-semibold text-slate-500 hover:bg-slate-50">
                    {{ $expanded === $c->id ? 'Ocultar historial' : 'Ver historial y cargos' }}
                    <x-heroicon-m-chevron-down @class(['h-3.5 w-3.5 transition', 'rotate-180' => $expanded === $c->id]) />
                </button>

                @if ($expanded === $c->id && $history)
                    <div class="space-y-3 border-t border-slate-100 bg-slate-50/70 p-4 text-xs">
                        <div>
                            <div class="mb-1 font-bold text-slate-700">Cargos pendientes</div>
                            @forelse ($history['charges'] as $ch)
                                <div class="flex justify-between gap-2 py-0.5"><span class="truncate text-slate-600">{{ $ch->description }} <span @class(['text-danger-600' => $ch->isOverdue(), 'text-slate-400' => ! $ch->isOverdue()])>· {{ $ch->due_date->format('d/m') }}</span></span><b>{{ money($ch->balance()) }}</b></div>
                            @empty
                                <p class="text-slate-400">Sin cargos pendientes</p>
                            @endforelse
                        </div>
                        <div>
                            <div class="mb-1 font-bold text-slate-700">Últimos pagos</div>
                            @forelse ($history['payments'] as $p)
                                <div class="flex justify-between gap-2 py-0.5"><span class="text-slate-600">{{ $p->paid_at->format('d/m/Y') }} · {{ $p->methodLabel() }}</span><b class="text-success-700">{{ money($p->amount) }}</b></div>
                            @empty
                                <p class="text-slate-400">Sin pagos</p>
                            @endforelse
                        </div>
                        <div>
                            <div class="mb-1 font-bold text-slate-700">Visitas anteriores</div>
                            @forelse ($history['visits'] as $v)
                                <div class="py-0.5 text-slate-600">{{ $v->visited_at->format('d/m/Y H:i') }} · <b>{{ \App\Support\Catalog::label('visit_results', $v->result) }}</b>
                                    @if ($v->promise_date) (promesa {{ $v->promise_date->format('d/m') }}) @endif
                                    @if ($v->notes)<span class="text-slate-400">· {{ $v->notes }}</span>@endif
                                </div>
                            @empty
                                <p class="text-slate-400">Sin visitas registradas</p>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="card">
                <x-ui.empty :icon="$view === 'pendientes' ? 'check-badge' : 'map-pin'"
                            :title="$view === 'pendientes' ? ($search ? 'Sin coincidencias' : '¡Sin clientes pendientes!') : 'Aún no registras visitas hoy'"
                            :description="$view === 'pendientes' ? 'No tienes clientes asignados con saldo por visitar.' : 'Los clientes visitados o cobrados hoy aparecerán aquí.'" />
            </div>
        @endforelse
    </div>

    {{-- Registrar pago --}}
    <x-ui.modal wire:model="showPay" title="Registrar pago" size="md">
        @if ($actionClient)
            <div class="mb-4 rounded-xl bg-slate-50 p-3">
                <div class="font-semibold text-slate-800">{{ $actionClient->full_name }}</div>
                <div class="text-xs text-slate-500">#{{ $actionClient->code }} · Saldo <b class="text-danger-600">{{ money($actionClient->balance) }}</b></div>
            </div>
        @endif
        <div class="space-y-3">
            <x-ui.input label="Importe recibido" type="number" inputmode="decimal" step="0.01" min="1" max="100000" prefix="$" wire:model="payForm.amount" name="payForm.amount" required class="text-lg" />
            <div>
                <label class="form-label">Método</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'deposito' => 'Depósito', 'tarjeta' => 'Tarjeta'] as $k => $l)
                        <label @class(['flex cursor-pointer items-center justify-center rounded-lg border px-3 py-2.5 text-sm font-semibold', 'border-brand-600 bg-brand-50 text-brand-700' => $payForm['method'] === $k, 'border-slate-300 text-slate-600' => $payForm['method'] !== $k])>
                            <input type="radio" class="sr-only" wire:model.live="payForm.method" value="{{ $k }}">{{ $l }}
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($payForm['method'] !== 'efectivo')
                <x-ui.input label="Referencia / autorización" wire:model="payForm.reference" name="payForm.reference" filter="text" maxlength="120" />
            @endif
            <x-ui.input label="Notas" wire:model="payForm.notes" name="payForm.notes" maxlength="190" placeholder="Opcional" />
            @include('livewire.field.partials.collection-evidence', ['model' => 'payEvidence', 'file' => $payEvidence, 'latField' => 'payForm.lat', 'lngField' => 'payForm.lng', 'lat' => $payForm['lat']])
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="success" icon="banknotes" wire:click="savePayment" wire:loading.attr="disabled" wire:target="savePayment,payEvidence">Registrar pago</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Registrar visita --}}
    <x-ui.modal wire:model="showVisit" title="Registrar visita" size="md">
        @if ($actionClient)
            <div class="mb-4 rounded-xl bg-slate-50 p-3">
                <div class="font-semibold text-slate-800">{{ $actionClient->full_name }}</div>
                <div class="text-xs text-slate-500">#{{ $actionClient->code }} · Saldo {{ money($actionClient->balance) }}</div>
            </div>
        @endif
        <div class="space-y-3">
            <div>
                <label class="form-label">Resultado</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['no_localizado' => ['No localizado', 'user-minus'], 'promesa' => ['Promesa de pago', 'hand-raised'], 'rechazo' => ['Rechazo', 'no-symbol'], 'cerrado' => ['Domicilio cerrado', 'lock-closed']] as $k => [$l, $i])
                        <label @class(['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-semibold', 'border-brand-600 bg-brand-50 text-brand-700' => $visitForm['result'] === $k, 'border-slate-300 text-slate-600' => $visitForm['result'] !== $k])>
                            <input type="radio" class="sr-only" wire:model.live="visitForm.result" value="{{ $k }}">
                            <x-dynamic-component :component="'heroicon-o-'.$i" class="h-4 w-4 shrink-0" /> {{ $l }}
                        </label>
                    @endforeach
                </div>
                @error('visitForm.result')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
            </div>
            @if ($visitForm['result'] === 'promesa')
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Pagará el" type="date" wire:model="visitForm.promise_date" name="visitForm.promise_date" :min="now()->toDateString()" required />
                    <x-ui.input label="Monto" type="number" inputmode="decimal" step="0.01" min="1" max="100000" prefix="$" wire:model="visitForm.promise_amount" name="visitForm.promise_amount" />
                </div>
            @endif
            <x-ui.textarea label="Notas" wire:model="visitForm.notes" name="visitForm.notes" rows="2" maxlength="190" placeholder="Con quién habló, observaciones..." />
            @include('livewire.field.partials.collection-evidence', ['model' => 'visitEvidence', 'file' => $visitEvidence, 'latField' => 'visitForm.lat', 'lngField' => 'visitForm.lng', 'lat' => $visitForm['lat']])
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveVisit" wire:loading.attr="disabled" wire:target="saveVisit,visitEvidence">Guardar visita</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
