<div>
    <x-ui.page-header :title="'Orden '.$o->folio" :subtitle="$o->customerName()" :breadcrumbs="['Instalaciones' => $canManage ? route('installations.index') : null, $o->folio => null]">
        @if ($phone)
            @php $digits = preg_replace('/\D/', '', $phone); $digits = strlen($digits) === 10 ? '52'.$digits : $digits; @endphp
            <x-ui.button variant="secondary" icon="chat-bubble-left-ellipsis" :href="'https://wa.me/'.$digits" :navigate="false" target="_blank">WhatsApp</x-ui.button>
        @endif
        @if ($coords)
            <x-ui.button variant="secondary" icon="map" :href="'https://www.google.com/maps/dir/?api=1&destination='.$coords['lat'].','.$coords['lng']" :navigate="false" target="_blank">Cómo llegar</x-ui.button>
        @endif
        @if (Route::has('documents.installation'))
            <x-ui.button variant="secondary" icon="document-text" :href="route('documents.installation', $o)" :navigate="false" target="_blank">PDF</x-ui.button>
        @endif
        @if ($canManage && ! in_array($o->status, ['activada', 'cancelada']))
            <x-ui.button variant="ghost" icon="x-circle" class="text-danger-600" wire:click="$set('showCancel', true)">Cancelar</x-ui.button>
        @endif
    </x-ui.page-header>

    {{-- Stepper del flujo --}}
    <div class="card mb-5 overflow-x-auto p-4">
        <ol class="flex min-w-[760px] items-center">
            @foreach (array_values($steps) as $i => $label)
                @php $done = $o->status !== 'cancelada' && $i < $step; $current = $o->status !== 'cancelada' && $i === $step; @endphp
                <li class="flex flex-1 items-center {{ $loop->last ? 'flex-none' : '' }}">
                    <div class="flex flex-col items-center gap-1.5">
                        <span @class([
                            'flex h-9 w-9 items-center justify-center rounded-full text-sm font-bold ring-4 transition',
                            'bg-success-600 text-white ring-success-100' => $done || ($current && $o->status === 'activada'),
                            'bg-brand-600 text-white ring-brand-100' => $current && $o->status !== 'activada',
                            'bg-slate-100 text-slate-400 ring-white' => ! $done && ! $current,
                        ])>
                            @if ($done || ($current && $o->status === 'activada'))<x-heroicon-m-check class="h-5 w-5" />@else{{ $i + 1 }}@endif
                        </span>
                        <span @class(['text-2xs font-semibold whitespace-nowrap', 'text-navy-900' => $done || $current, 'text-slate-400' => ! $done && ! $current])>{{ $label }}</span>
                    </div>
                    @if (! $loop->last)
                        <div @class(['mx-2 mb-5 h-0.5 flex-1 rounded', 'bg-success-500' => $done, 'bg-slate-200' => ! $done])></div>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($o->status === 'cancelada')
            <div class="mt-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-700"><b>Orden cancelada.</b> {{ $o->notes }}</div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="min-w-0 space-y-5 xl:col-span-2">

            {{-- ============== 1-3. COBERTURA + COTIZACIÓN ============== --}}
            @if ($o->status === 'cotizacion')
                <x-ui.card title="Validación de cobertura">
                    <x-slot:actions>@if ($canManage)<x-ui.button size="sm" variant="secondary" icon="signal" wire:click="checkCoverage">Revalidar</x-ui.button>@endif</x-slot:actions>
                    @if ($o->prospect && $o->prospect->lat)
                        <div @class(['flex items-start gap-3 rounded-lg p-3', 'bg-success-50 text-success-700' => $o->prospect->has_coverage, 'bg-danger-50 text-danger-700' => ! $o->prospect->has_coverage])>
                            <x-dynamic-component :component="$o->prospect->has_coverage ? 'heroicon-o-signal' : 'heroicon-o-signal-slash'" class="h-6 w-6 shrink-0" />
                            <div class="text-sm">
                                <div class="font-semibold">{{ $o->prospect->has_coverage ? 'Con cobertura' : 'Sin cobertura' }}</div>
                                <div class="text-xs">Repetidor más cercano {{ $o->prospect->nearestRepeater?->code ?? '—' }} a {{ $o->prospect->distance_km }} km
                                    @if ($o->prospect->nearestRepeater) · uso {{ $o->prospect->nearestRepeater->utilization() }}%@endif</div>
                            </div>
                        </div>
                    @elseif ($coords)
                        <p class="text-sm text-slate-600">Ubicación del cliente registrada. Repetidor asignado: <b>{{ $repeater?->code ?? '—' }}</b> @if ($distance) a {{ $distance }} km @endif</p>
                    @else
                        <p class="text-sm text-warning-700">Sin coordenadas: registra la ubicación en el prospecto para validar cobertura.</p>
                    @endif
                </x-ui.card>

                <x-ui.card title="Cotización">
                    <x-slot:actions>@if ($canManage)<x-ui.button size="sm" variant="ghost" icon="sparkles" wire:click="suggestQuote">Sugerir partidas</x-ui.button>@endif</x-slot:actions>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <x-ui.select label="Plan" wire:model="quote.plan_id" :options="$plans" placeholder="Selecciona..." required />
                        <x-ui.select label="Promoción" wire:model="quote.promotion_id" :options="$promotions" placeholder="Sin promoción" />
                        <x-ui.select label="Repetidor" wire:model="quote.repeater_id" :options="$repeaters" placeholder="Sin asignar" />
                    </div>
                    <div class="mt-4 overflow-hidden rounded-lg border border-slate-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500 uppercase"><tr><th class="px-3 py-2 text-left">Concepto</th><th class="w-36 px-3 py-2 text-right">Importe</th><th class="w-10"></th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($items as $i => $item)
                                    <tr wire:key="item-{{ $i }}">
                                        <td class="px-2 py-1.5"><input type="text" wire:model="items.{{ $i }}.concepto" data-filter="text" maxlength="120" class="form-input" placeholder="Concepto"></td>
                                        <td class="px-2 py-1.5"><input type="number" step="0.01" min="-100000" max="100000" wire:model.live.debounce.400ms="items.{{ $i }}.importe" class="form-input text-right"></td>
                                        <td class="px-1"><button type="button" wire:click="removeItem({{ $i }})" class="rounded p-1 text-slate-400 hover:text-danger-600"><x-heroicon-o-trash class="h-4 w-4" /></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50/70">
                                <tr>
                                    <td class="px-3 py-2"><button type="button" wire:click="addItem" class="link text-xs">+ Agregar partida</button></td>
                                    <td class="px-3 py-2 text-right text-base font-bold text-navy-900">{{ money(max(0, collect($items)->sum(fn ($i) => (float) $i['importe']))) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @error('items.*.concepto')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                    <x-ui.textarea label="Notas" wire:model="quote.notes" rows="2" maxlength="2000" class="mt-3" />
                    @if ($canManage)
                        <div class="mt-4 flex flex-wrap justify-end gap-2">
                            <x-ui.button variant="secondary" icon="check" wire:click="saveQuote">Guardar cotización</x-ui.button>
                            <x-ui.button variant="success" icon="hand-thumb-up" wire:click="accept" wire:confirm="¿El cliente aceptó la cotización?">Cliente acepta</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- ============== 4-5. ACEPTACIÓN / PROGRAMACIÓN ============== --}}
            @if (in_array($o->status, ['aceptada', 'programada']))
                <x-ui.card :title="$o->status === 'aceptada' ? 'Programar instalación' : 'Instalación programada'">
                    @if ($o->scheduled_at)
                        <div class="mb-4 flex flex-wrap items-center gap-4 rounded-lg bg-purple-50 p-4">
                            <x-heroicon-o-calendar-days class="h-8 w-8 text-purple-600" />
                            <div>
                                <div class="text-lg font-bold capitalize text-navy-900">{{ $o->scheduled_at->translatedFormat('l d \d\e F, H:i') }}</div>
                                <div class="text-sm text-slate-600">Técnico: <b>{{ $o->technician?->name ?? 'Sin asignar' }}</b> · {{ $o->scheduled_at->diffForHumans() }}</div>
                            </div>
                        </div>
                    @endif
                    @if ($canManage)
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <x-ui.input label="Fecha" type="date" wire:model="schedule.date" required />
                            <x-ui.input label="Hora" type="time" wire:model="schedule.time" required />
                            <x-ui.select label="Técnico" wire:model="schedule.technician_id" :options="$technicians" placeholder="Selecciona..." required />
                        </div>
                        <div class="mt-3 flex justify-end">
                            <x-ui.button variant="secondary" icon="calendar" wire:click="saveSchedule">{{ $o->scheduled_at ? 'Reprogramar' : 'Programar' }}</x-ui.button>
                        </div>
                    @endif
                    @if ($o->status === 'programada')
                        <div class="mt-4 border-t border-slate-100 pt-4">
                            <x-ui.button variant="primary" icon="play" class="w-full py-3 text-base sm:w-auto" wire:click="start">Iniciar instalación</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            {{-- ============== 6. INSTALACIÓN (checklist) ============== --}}
            @if (in_array($o->status, ['en_proceso', 'instalada', 'activada']))
                @php $editable = $o->status === 'en_proceso'; @endphp
                <x-ui.card title="Checklist de instalación">
                    <x-slot:actions><span class="text-sm font-bold text-teal-700">{{ $o->checklistProgress() }}%</span></x-slot:actions>
                    <x-ui.progress :value="$o->checklistProgress()" color="teal" class="mb-4" />
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach (config('wisp.installation_checklist') as $key => $label)
                            @php $checked = (bool) ($o->checklist[$key] ?? false); @endphp
                            <button type="button" @if ($editable) wire:click="toggleCheck('{{ $key }}')" @else disabled @endif
                                    @class(['flex items-center gap-3 rounded-lg border p-3.5 text-left text-sm font-medium transition', 'border-success-200 bg-success-50 text-success-700' => $checked, 'border-slate-200 bg-white text-slate-700 hover:border-brand-300' => ! $checked && $editable, 'border-slate-200 bg-slate-50 text-slate-500' => ! $checked && ! $editable])>
                                <span @class(['flex h-6 w-6 shrink-0 items-center justify-center rounded-md', 'bg-success-600 text-white' => $checked, 'border-2 border-slate-300' => ! $checked])>
                                    @if ($checked)<x-heroicon-m-check class="h-4 w-4" />@endif
                                </span>
                                {{ $label }}
                                @if (in_array($key, ['fotografia', 'firma']) && ! $checked)<span class="ml-auto text-2xs font-normal text-slate-400">automático</span>@endif
                            </button>
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card title="Mediciones">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-ui.input label="Señal" type="number" step="0.1" min="-120" max="0" wire:model="measures.signal_dbm" suffix="dBm" :disabled="! $editable" inputmode="decimal" />
                        <x-ui.input label="Bajada" type="number" step="0.1" min="0" max="10000" wire:model="measures.speed_down" suffix="Mbps" :disabled="! $editable" inputmode="decimal" />
                        <x-ui.input label="Subida" type="number" step="0.1" min="0" max="10000" wire:model="measures.speed_up" suffix="Mbps" :disabled="! $editable" inputmode="decimal" />
                    </div>
                    @if ($editable)
                        <div class="mt-3 flex justify-end"><x-ui.button variant="secondary" icon="check" wire:click="saveMeasures">Guardar mediciones</x-ui.button></div>
                    @endif
                </x-ui.card>

                <x-ui.card title="Fotografías / evidencias">
                    @if ($editable)
                        <form wire:submit="uploadPhoto" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end">
                            <x-ui.select label="Tipo" wire:model="photoKind" :options="config('wisp.evidence_kinds')" class="sm:w-48" />
                            <div class="flex-1">
                                <label class="form-label">Foto (cámara o galería)</label>
                                <input type="file" accept="image/*" capture="environment" wire:model="photo" class="form-input file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1 file:text-sm file:font-semibold file:text-brand-700">
                                @error('photo')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                            </div>
                            <x-ui.button type="submit" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="photo,uploadPhoto">Subir</x-ui.button>
                        </form>
                        <div wire:loading wire:target="photo" class="mb-2 text-xs text-slate-500">Cargando imagen...</div>
                    @endif
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @forelse ($photos as $a)
                            <div class="group relative overflow-hidden rounded-lg border border-slate-200" wire:key="ph{{ $a->id }}">
                                <a href="{{ $a->url() }}" target="_blank"><img src="{{ $a->url() }}" alt="" class="aspect-square w-full object-cover"></a>
                                <div class="flex items-center justify-between bg-white px-2 py-1 text-2xs text-slate-500">
                                    <span>{{ config('wisp.evidence_kinds.'.$a->kind, $a->kind) }}</span>
                                    @if ($editable)<button type="button" wire:click="deletePhoto({{ $a->id }})" wire:confirm="¿Eliminar fotografía?" class="text-danger-600"><x-heroicon-o-trash class="h-3.5 w-3.5" /></button>@endif
                                </div>
                            </div>
                        @empty
                            <p class="col-span-full text-sm text-slate-400">Sin fotografías.</p>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card title="Firma del cliente">
                    @if ($o->signature_path)
                        <div class="mb-3 flex items-center gap-4 rounded-lg bg-slate-50 p-3">
                            <img src="{{ \App\Support\Evidence::url($o->signature_path) }}" alt="Firma" class="h-16 rounded bg-white ring-1 ring-slate-200">
                            <div class="text-sm"><div class="font-semibold text-slate-800">{{ $o->signed_by_name }}</div><div class="text-xs text-slate-500">Firma registrada</div></div>
                        </div>
                    @endif
                    @if ($editable)
                        <div wire:ignore x-data="signaturePad('signature')" class="space-y-2">
                            <canvas x-ref="pad" class="h-48 w-full touch-none rounded-lg border-2 border-dashed border-slate-300 bg-white"></canvas>
                            <div class="flex items-center justify-between text-xs text-slate-500">
                                <span x-text="empty ? 'Firme dentro del recuadro' : 'Firma capturada'"></span>
                                <button type="button" @click="clear()" class="font-semibold text-brand-600">Limpiar</button>
                            </div>
                        </div>
                        @error('signature')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                            <x-ui.input label="Nombre de quien firma" wire:model="signedBy" filter="name" maxlength="150" class="flex-1" />
                            <x-ui.button icon="pencil" wire:click="saveSignature">Guardar firma</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>

                @if ($editable)
                    <x-ui.button variant="success" icon="check-circle" class="w-full py-3 text-base" wire:click="complete" wire:confirm="¿Finalizar la instalación?">Finalizar instalación</x-ui.button>
                @endif
            @endif

            {{-- ============== 7. ACTIVACIÓN ============== --}}
            @if ($o->status === 'instalada')
                <x-ui.card title="Activación del servicio">
                    <dl class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <x-ui.detail label="Cliente">{{ $o->client ? '#'.$o->client->code.' '.$o->client->full_name : 'Se creará desde el prospecto' }}</x-ui.detail>
                        <x-ui.detail label="Plan">{{ $o->plan?->name }} {{ $o->plan?->speedLabel() }}</x-ui.detail>
                        <x-ui.detail label="Repetidor">{{ $repeater?->code }}</x-ui.detail>
                        <x-ui.detail label="Cotización">{{ money($o->quote_amount) }}</x-ui.detail>
                    </dl>
                    @if ($o->promotion)
                        <div class="mt-3 rounded-lg bg-purple-50 p-3 text-sm text-purple-700"><b>Promoción:</b> {{ $o->promotion->name }} · {{ $o->promotion->valueLabel() }}</div>
                    @endif
                    @if ($canManage)
                        <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                            <x-ui.toggle wire:model.live="chargeInstallation" label="Generar cargo de instalación" :description="(float) $o->quote_amount > 0 ? 'Se cargará '.money($o->quote_amount).' al estado de cuenta.' : 'La cotización es $0 (promoción o sin costo): no se genera cargo.'" />
                            <p class="text-xs text-slate-500">Al activar: se crea/actualiza el cliente, se da de alta en MikroTik, se envía bienvenida, el prospecto pasa a <b>Convertido</b> y, si llegó con código, se registra el referido.</p>
                            <x-ui.button variant="success" icon="bolt" class="w-full py-3 text-base sm:w-auto" wire:click="activate" wire:confirm="¿Activar el servicio del cliente?">Activar servicio</x-ui.button>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-slate-500">Instalación finalizada. La activación la realiza la oficina.</p>
                    @endif
                </x-ui.card>
            @endif

            @if ($o->status === 'activada')
                <div class="card flex flex-col items-start gap-4 border-success-200 bg-success-50/60 p-5 sm:flex-row sm:items-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-success-600 text-white"><x-heroicon-o-check-badge class="h-7 w-7" /></span>
                    <div class="flex-1">
                        <div class="text-lg font-bold text-navy-900">Cliente activo</div>
                        <div class="text-sm text-slate-600">Activado {{ $o->activated_at?->format('d/m/Y H:i') }}@if ($o->client) · cliente #{{ $o->client->code }} · {{ \App\Support\Catalog::label('client_status', $o->client->service_status) }}@endif</div>
                    </div>
                    @if ($o->client && Route::has('clients.show'))
                        <x-ui.button icon="user" :href="route('clients.show', $o->client)">Ver cliente</x-ui.button>
                    @endif
                </div>
            @endif

            @if (in_array($o->status, ['aceptada', 'programada', 'en_proceso', 'instalada', 'activada']) && ! empty($o->quote_items))
                <x-ui.card title="Cotización aceptada" :padding="false">
                    <x-ui.table>
                        <x-slot:head><th>Concepto</th><th class="text-right">Importe</th></x-slot:head>
                        @foreach ($o->quote_items as $item)
                            <tr><td>{{ $item['concepto'] ?? '' }}</td><td class="text-right">{{ money($item['importe'] ?? 0) }}</td></tr>
                        @endforeach
                        <x-slot:footer><tr><td class="px-4 py-2.5">Total @if ($o->quote_accepted_at)<span class="text-xs font-normal text-slate-500">· aceptada {{ $o->quote_accepted_at->format('d/m/Y') }}</span>@endif</td><td class="px-4 py-2.5 text-right">{{ money($o->quote_amount) }}</td></tr></x-slot:footer>
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        {{-- ============== COLUMNA DERECHA ============== --}}
        <div class="space-y-5">
            <x-ui.card title="Cliente / prospecto">
                <div class="flex items-center gap-3">
                    <x-ui.avatar :name="$o->customerName()" size="lg" :color="$o->client ? 'brand' : 'purple'" />
                    <div class="min-w-0">
                        <div class="font-bold text-navy-900">{{ $o->customerName() }}</div>
                        <div class="text-xs text-slate-500">
                            @if ($o->client)Cliente #{{ $o->client->code }}@else Prospecto · {{ \App\Support\Catalog::label('prospect_status', $o->prospect?->status) }}@endif
                        </div>
                    </div>
                </div>
                <dl class="mt-4 space-y-3">
                    <x-ui.detail label="Teléfono">@if ($phone)<a class="link" href="tel:{{ $phone }}">{{ $phone }}</a>@endif</x-ui.detail>
                    <x-ui.detail label="Dirección">{{ $o->client?->address ?? $o->prospect?->address }}</x-ui.detail>
                    <x-ui.detail label="Plan">{{ $o->plan?->name }} {{ $o->plan ? '· '.$o->plan->speedLabel().' · '.money($o->plan->price) : '' }}</x-ui.detail>
                    <x-ui.detail label="Técnico">{{ $o->technician?->name }}</x-ui.detail>
                    <x-ui.detail label="Programada">{{ $o->scheduled_at?->format('d/m/Y H:i') }}</x-ui.detail>
                    <x-ui.detail label="Repetidor">{{ $repeater ? $repeater->code.' · '.$repeater->name : '' }} {{ $distance ? '· '.$distance.' km' : '' }}</x-ui.detail>
                </dl>
            </x-ui.card>

            @if ($coords)
                <x-ui.card title="Ubicación" :padding="false">
                    <x-ui.map id="order-map" height="260px" :center="[$coords['lat'], $coords['lng']]" :zoom="15" :markers="$markers" :lines="$lines" fit class="rounded-t-none border-0" :layers-control="false" />
                </x-ui.card>
            @endif

            <x-ui.card title="Historial">
                <ol class="space-y-3 text-xs">
                    @forelse ($logs as $log)
                        <li class="flex gap-2"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                            <div><div class="text-slate-700">{{ $log->user_name }} {{ $log->description }}</div><div class="text-slate-400">{{ $log->created_at?->format('d/m/Y H:i') }}</div></div></li>
                    @empty
                        <li class="text-slate-400">Creada {{ $o->created_at->format('d/m/Y H:i') }}</li>
                    @endforelse
                </ol>
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal wire:model="showCancel" title="Cancelar orden" size="md">
        <x-ui.textarea label="Motivo" wire:model="cancelReason" rows="3" maxlength="190" required />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Volver</x-ui.button>
            <x-ui.button variant="danger" wire:click="cancel">Cancelar orden</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
