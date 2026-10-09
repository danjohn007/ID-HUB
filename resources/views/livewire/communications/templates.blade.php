@php use App\Services\Messaging\MessageCatalog as MC; @endphp
<div>
    <x-ui.page-header title="Plantillas de mensajes" subtitle="Textos de cobranza automática y avisos por WhatsApp, SMS, correo y notificación push">
        @if (Route::has('communications.messages'))
            <x-ui.button variant="secondary" icon="chat-bubble-left-right" :href="route('communications.messages')">Bitácora de mensajes</x-ui.button>
        @endif
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
        {{-- Eventos --}}
        <x-ui.card :padding="false" title="Eventos">
            <div class="max-h-[640px] divide-y divide-slate-100 overflow-y-auto">
                @foreach (config('wisp.message_events') as $key => $label)
                    @php $rows = $templates[$key] ?? collect(); @endphp
                    <button type="button" wire:click="select('{{ $key }}')" wire:key="ev-{{ $key }}"
                            @class(['flex w-full items-center gap-3 px-4 py-2.5 text-left transition', 'bg-brand-50' => $event === $key, 'hover:bg-slate-50' => $event !== $key])>
                        <span class="min-w-0 flex-1">
                            <span @class(['block truncate text-sm font-semibold', 'text-brand-700' => $event === $key, 'text-slate-700' => $event !== $key])>{{ $label }}</span>
                            <span class="mt-1 flex gap-1">
                                @foreach (config('wisp.channels') as $ch => $chLabel)
                                    @php $t = $rows->firstWhere('channel', $ch); @endphp
                                    <span title="{{ $chLabel }}: {{ $t ? ($t->active ? 'personalizada activa' : 'desactivada') : 'texto por defecto' }}"
                                          @class(['flex h-5 w-5 items-center justify-center rounded',
                                                  'bg-success-100 text-success-700' => $t && $t->active,
                                                  'bg-danger-50 text-danger-400 line-through' => $t && ! $t->active,
                                                  'bg-slate-100 text-slate-400' => ! $t])>
                                        <x-dynamic-component :component="'heroicon-o-'.MC::CHANNEL_ICONS[$ch]" class="h-3 w-3" />
                                    </span>
                                @endforeach
                            </span>
                        </span>
                        <x-heroicon-m-chevron-right class="h-4 w-4 text-slate-300" />
                    </button>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Editor --}}
        <div class="min-w-0 space-y-5">
            <x-ui.card :padding="false">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-[15px] font-bold text-navy-900">{{ config('wisp.message_events.'.$event) }}</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            @if (! $exists) Usando el texto por defecto del sistema @elseif ($isCustom) Texto personalizado por tu empresa @else Texto por defecto @endif
                            · Canales predeterminados del WISP: {{ collect($defaultChannels)->map(fn ($c) => config('wisp.channels.'.$c, $c))->implode(', ') }}
                        </p>
                    </div>
                    <button type="button" wire:click="toggleActive" class="flex items-center gap-2.5" title="Activar / desactivar este aviso en este canal">
                        <span @class(['relative inline-flex h-5 w-9 shrink-0 rounded-full transition', 'bg-brand-600' => $form['active'], 'bg-slate-300' => ! $form['active']])>
                            <span @class(['absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition', 'translate-x-4' => $form['active']])></span>
                        </span>
                        <span class="text-sm font-medium {{ $form['active'] ? 'text-slate-700' : 'text-slate-500' }}">{{ $form['active'] ? 'Activa' : 'Desactivada' }}</span>
                    </button>
                </div>
                <div class="px-5 pt-3">
                    <nav class="-mb-px flex gap-1 overflow-x-auto border-b border-slate-200">
                        @foreach (config('wisp.channels') as $ch => $chLabel)
                            <button type="button" wire:click="$set('channel', '{{ $ch }}')"
                                    @class(['flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3.5 py-2.5 text-sm font-semibold transition',
                                            'border-brand-600 text-brand-700' => $channel === $ch,
                                            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $channel !== $ch])>
                                <x-dynamic-component :component="'heroicon-o-'.MC::CHANNEL_ICONS[$ch]" class="h-4 w-4" />{{ $chLabel }}
                            </button>
                        @endforeach
                    </nav>
                </div>

                <div class="grid grid-cols-1 gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_300px]">
                    <div class="space-y-4" x-data="{ insert(v) { const t = this.$refs.body; const s = t.selectionStart ?? t.value.length; t.setRangeText(v, s, t.selectionEnd ?? s, 'end'); t.dispatchEvent(new Event('input')); t.focus(); } }">
                        @if (in_array($channel, ['email', 'push']))
                            <x-ui.input :label="$channel === 'email' ? 'Asunto del correo' : 'Título de la notificación'" wire:model.live.debounce.400ms="form.subject" />
                        @endif
                        <x-ui.textarea label="Mensaje" wire:model.live.debounce.400ms="form.body" x-ref="body" rows="7" />
                        <div>
                            <div class="mb-1.5 text-xs font-medium text-slate-500">Variables (clic para insertar)</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach (\App\Models\NotificationTemplate::VARIABLES as $v)
                                    <button type="button" @click="insert('{{ $v }}')" class="rounded-md bg-brand-50 px-1.5 py-0.5 font-mono text-[11px] text-brand-700 ring-1 ring-brand-100 hover:bg-brand-100">{{ $v }}</button>
                                @endforeach
                            </div>
                        </div>
                        @if ($channel === 'whatsapp')
                            <div class="flex gap-2 rounded-lg bg-warning-50 px-3 py-2.5 text-xs text-warning-800">
                                <x-heroicon-o-information-circle class="h-4 w-4 shrink-0" />
                                <span>WhatsApp Business (Meta) exige <b>plantillas aprobadas</b> para mensajes iniciados por la empresa fuera de la ventana de 24 h desde el último mensaje del cliente. Registra este texto como plantilla en tu cuenta de Meta para que los avisos automáticos se entreguen.</span>
                            </div>
                        @elseif ($channel === 'sms')
                            <p class="text-xs text-slate-500">Los SMS se cobran por segmentos de 160 caracteres (70 si llevan acentos o emojis en algunos operadores).</p>
                        @endif
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4">
                            <x-ui.button variant="ghost" icon="arrow-uturn-left" wire:click="restoreDefault">Restaurar texto por defecto</x-ui.button>
                            <div class="flex items-center gap-2">
                                @if ($dirty)<span class="text-xs text-warning-700">Cambios sin guardar</span>@endif
                                <x-ui.button icon="check" wire:click="save">Guardar plantilla</x-ui.button>
                            </div>
                        </div>
                    </div>

                    {{-- Vista previa --}}
                    <div class="space-y-3">
                        <x-ui.select label="Cliente de ejemplo" wire:model.live="sampleId" :options="$samples" />
                        @if ($channel === 'email')
                            <div class="overflow-hidden rounded-xl border border-slate-200">
                                <div class="border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                                    <div class="text-slate-500">De: <span class="text-slate-700">{{ tenant()->name }}</span></div>
                                    <div class="font-semibold text-slate-800">{{ $previewSubject ?: '(sin asunto)' }}</div>
                                </div>
                                <div class="whitespace-pre-line break-words p-3 text-sm text-slate-700">{{ $preview }}</div>
                            </div>
                        @elseif ($channel === 'push')
                            <div class="rounded-2xl bg-navy-900 p-3">
                                <div class="rounded-xl bg-white/95 p-3 shadow">
                                    <div class="flex items-center gap-2 text-[11px] text-slate-500"><span class="flex h-4 w-4 items-center justify-center rounded bg-brand-600 text-[8px] font-bold text-white">{{ initials(tenant()->name) }}</span>{{ tenant()->name }} · ahora</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900">{{ $previewSubject }}</div>
                                    <div class="line-clamp-4 text-sm text-slate-700">{{ $preview }}</div>
                                </div>
                            </div>
                        @else
                            <div class="rounded-2xl bg-[#ECE5DD] p-3">
                                <div class="ml-auto max-w-[95%] rounded-xl rounded-tr-sm bg-[#D9FDD3] px-3 py-2 text-sm shadow-xs">
                                    <div class="whitespace-pre-line break-words text-slate-800">{{ $preview }}</div>
                                    <div class="mt-1 text-right text-[10.5px] text-slate-500">{{ now()->format('H:i') }} ✓✓</div>
                                </div>
                            </div>
                        @endif
                        <p class="text-[11px] text-slate-500">{{ mb_strlen($preview) }} caracteres @if ($channel === 'sms') · {{ max(1, (int) ceil(mb_strlen($preview) / 160)) }} SMS @endif</p>

                        <div class="rounded-xl border border-slate-200 p-3">
                            <div class="mb-2 text-xs font-semibold text-slate-700">Enviar prueba</div>
                            @if ($channel === 'push')
                                <p class="text-xs text-slate-500">La prueba de notificaciones push requiere un dispositivo con la app del cliente.</p>
                            @else
                                <div class="flex gap-2">
                                    <x-ui.input wire:model="testTo" :type="$channel === 'email' ? 'email' : 'tel'" :placeholder="$channel === 'email' ? 'correo@ejemplo.com' : '55 1234 5678'" class="flex-1" />
                                    <x-ui.button variant="secondary" icon="paper-airplane" wire:click="sendTest" title="Enviar prueba" />
                                </div>
                                <p class="mt-1.5 text-[11px] text-slate-500">Usa los datos del cliente de ejemplo. Se registra en la bitácora como "Envío de prueba".</p>
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
