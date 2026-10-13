@php use App\Services\Messaging\MessageCatalog as MC; @endphp
<div>
    <x-ui.page-header :title="$campaign ? 'Editar campaña' : 'Nueva campaña'"
                      :breadcrumbs="['Campañas' => route('communications.campaigns'), ($campaign ? $campaign->name : 'Nueva') => null]">
        <x-ui.button variant="secondary" :href="$campaign ? route('communications.campaigns.show', $campaign) : route('communications.campaigns')">Cancelar</x-ui.button>
        <x-ui.button variant="secondary" icon="document" wire:click="saveDraft">Guardar borrador</x-ui.button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card title="1. Destinatarios" subtitle="Elige a quién va dirigida la campaña">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach (config('wisp.campaign_segments') as $key => $label)
                        <button type="button" wire:click="$set('form.segment', '{{ $key }}')"
                                @class(['rounded-xl border px-3 py-2.5 text-left text-sm font-semibold transition',
                                        'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-100' => $form['segment'] === $key,
                                        'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50' => $form['segment'] !== $key])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if (in_array($form['segment'], \App\Services\Messaging\CampaignService::VALUE_SEGMENTS))
                    <x-ui.select class="mt-4" :label="['zone' => 'Zona', 'repeater' => 'Repetidor', 'plan' => 'Plan'][$form['segment']]"
                                 wire:model.live="form.segment_value" :options="$segmentOptions" placeholder="Selecciona..." required />
                @endif
                @error('form.segment')<p class="mt-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror

                <div class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl bg-slate-50 px-4 py-3">
                    <div>
                        <div class="text-xs text-slate-500">Clientes en el segmento</div>
                        <div class="text-xl font-bold text-navy-900">{{ number_format($counts['segment']) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-500">Recibirán el mensaje por {{ config('wisp.channels.'.$form['channel']) }}</div>
                        <div class="text-xl font-bold text-brand-600">{{ number_format($counts['reachable']) }}</div>
                    </div>
                    @if ($counts['segment'] > $counts['reachable'])
                        <div class="text-xs text-warning-700">{{ number_format($counts['segment'] - $counts['reachable']) }} sin dato de contacto para este canal</div>
                    @endif
                    <span wire:loading class="text-xs text-slate-400">Calculando...</span>
                </div>
            </x-ui.card>

            <x-ui.card title="2. Mensaje">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input label="Nombre de la campaña" wire:model="form.name" filter="text" maxlength="150" placeholder="Ej. Promoción Buen Fin" required />
                    <div>
                        <label class="form-label">Canal <span class="text-danger-600">*</span></label>
                        <div class="grid grid-cols-4 gap-1 rounded-lg bg-slate-100 p-1">
                            @foreach (config('wisp.channels') as $k => $l)
                                <button type="button" wire:click="$set('form.channel', '{{ $k }}')" @class(['flex items-center justify-center gap-1 rounded-md px-1 py-1.5 text-xs font-semibold transition', 'bg-white text-navy-900 shadow-xs' => $form['channel'] === $k, 'text-slate-500 hover:text-slate-700' => $form['channel'] !== $k])>
                                    <x-dynamic-component :component="'heroicon-o-'.MC::CHANNEL_ICONS[$k]" class="h-3.5 w-3.5" />{{ $l }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    @if (in_array($form['channel'], ['email', 'push']))
                        <x-ui.input label="Asunto / título" wire:model.live.debounce.400ms="form.subject" maxlength="150" class="md:col-span-2" required />
                    @endif
                    <div class="md:col-span-2" x-data="{ insert(v) { const t = this.$refs.body; const s = t.selectionStart ?? t.value.length; t.setRangeText(v, s, t.selectionEnd ?? s, 'end'); t.dispatchEvent(new Event('input')); t.focus(); } }">
                        <x-ui.textarea label="Mensaje" wire:model.live.debounce.400ms="form.body" x-ref="body" rows="6" maxlength="1600" required />
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            <span class="mr-1 text-xs text-slate-500">Variables:</span>
                            @foreach (\App\Models\NotificationTemplate::VARIABLES as $v)
                                <button type="button" @click="insert('{{ $v }}')" class="rounded-md bg-brand-50 px-1.5 py-0.5 font-mono text-[11px] text-brand-700 ring-1 ring-brand-100 hover:bg-brand-100">{{ $v }}</button>
                            @endforeach
                        </div>
                        @if ($form['channel'] === 'whatsapp')
                            <p class="mt-3 flex gap-2 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-800">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" />
                                Con la API oficial de WhatsApp (Meta), los mensajes iniciados por la empresa fuera de la ventana de 24 h requieren una plantilla aprobada por Meta.
                            </p>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="3. Envío">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (['now' => ['Enviar ahora', 'Se envía por lotes en este momento', 'paper-airplane'], 'schedule' => ['Programar', 'Elige fecha y hora de envío', 'calendar-days']] as $k => [$l, $d, $i])
                        <button type="button" wire:click="$set('when', '{{ $k }}')" @class(['flex items-start gap-3 rounded-xl border p-3 text-left transition', 'border-brand-500 bg-brand-50 ring-2 ring-brand-100' => $when === $k, 'border-slate-200 hover:bg-slate-50' => $when !== $k])>
                            <x-dynamic-component :component="'heroicon-o-'.$i" class="mt-0.5 h-5 w-5 {{ $when === $k ? 'text-brand-600' : 'text-slate-400' }}" />
                            <span><span class="block text-sm font-semibold text-slate-800">{{ $l }}</span><span class="block text-xs text-slate-500">{{ $d }}</span></span>
                        </button>
                    @endforeach
                </div>
                @if ($when === 'schedule')
                    <x-ui.input type="datetime-local" label="Fecha y hora de envío" wire:model="form.scheduled_at" class="mt-4 max-w-xs" hint="Zona horaria: {{ config('app.timezone') }}" />
                @endif
                <div class="mt-5 flex justify-end">
                    <x-ui.button :icon="$when === 'schedule' ? 'calendar-days' : 'paper-airplane'" wire:click="submit"
                                 wire:confirm="{{ $when === 'schedule' ? '¿Programar la campaña?' : '¿Enviar ahora a '.number_format($counts['reachable']).' destinatarios?' }}">
                        {{ $when === 'schedule' ? 'Programar envío' : 'Enviar a '.number_format($counts['reachable']).' destinatarios' }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        </div>

        {{-- Vista previa --}}
        <div class="space-y-5">
            <x-ui.card title="Vista previa" subtitle="Así lo recibirá un destinatario de ejemplo">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="ghost" icon="arrow-path" wire:click="nextSample" title="Otro destinatario" />
                </x-slot:actions>
                @if ($sample)
                    <div class="mb-3 flex items-center gap-2">
                        <x-ui.avatar :name="$sample->full_name" size="sm" />
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-slate-800">{{ $sample->full_name }}</div>
                            <div class="truncate text-xs text-slate-500">#{{ $sample->code }} · {{ $form['channel'] === 'email' ? $sample->email : ($sample->whatsapp ?: $sample->phone) }}</div>
                        </div>
                    </div>
                @else
                    <p class="mb-3 text-xs text-slate-500">No hay clientes en el segmento para mostrar un ejemplo.</p>
                @endif
                <div class="rounded-2xl bg-[#ECE5DD] p-3">
                    <div class="ml-auto max-w-[95%] rounded-xl rounded-tr-sm bg-[#D9FDD3] px-3 py-2 text-sm shadow-xs">
                        @if ($form['subject'] && in_array($form['channel'], ['email', 'push']))<div class="mb-1 font-semibold text-slate-800">{{ $form['subject'] }}</div>@endif
                        <div class="whitespace-pre-line break-words text-slate-800">{{ $preview ?: '…' }}</div>
                        <div class="mt-1 text-right text-[10.5px] text-slate-500">{{ now()->format('H:i') }} ✓✓</div>
                    </div>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">
                    {{ mb_strlen($preview) }} caracteres
                    @if ($form['channel'] === 'sms') · {{ max(1, (int) ceil(mb_strlen($preview) / 160)) }} SMS por destinatario @endif
                </p>
            </x-ui.card>
            <x-ui.card>
                <div class="flex gap-3 text-xs text-slate-600">
                    <x-heroicon-o-light-bulb class="h-5 w-5 shrink-0 text-warning-600" />
                    <div class="space-y-1.5">
                        <p>El envío se realiza por lotes para no saturar el servidor; puedes cerrar la página y continuará automáticamente cada minuto.</p>
                        <p><span class="font-mono">{link_pago}</span> genera un enlace "Pagar ahora" por el saldo de cada cliente (vacío si no tiene adeudo).</p>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
