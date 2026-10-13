@php
    use App\Services\Messaging\MessageCatalog as MC;
    $channels = config('wisp.channels');
    $channelSub = fn ($counts) => collect($channels)->map(fn ($l, $k) => $l.' '.number_format($counts[$k] ?? 0))->implode(' · ');
@endphp
<div>
    <x-ui.page-header title="Mensajes" subtitle="Bitácora de WhatsApp, SMS, correo y notificaciones push enviados a tus clientes">
        @if ($filterStatus === 'failed')
            <x-ui.button variant="secondary" icon="arrow-path" wire:click="resendAllFailed" wire:confirm="¿Reenviar todos los mensajes fallidos del filtro actual?">Reenviar fallidos</x-ui.button>
        @endif
        @if (Route::has('communications.templates'))
            <x-ui.button variant="secondary" icon="document-text" :href="route('communications.templates')">Plantillas</x-ui.button>
        @endif
        <x-ui.button icon="paper-airplane" wire:click="openCompose">Nuevo mensaje</x-ui.button>
    </x-ui.page-header>

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat label="Enviados hoy" :value="number_format($kpi['today']->sum())" icon="paper-airplane" color="brand" :sub="$channelSub($kpi['today'])" />
        <x-ui.stat label="Enviados este mes" :value="number_format($kpi['month']->sum())" icon="chat-bubble-left-right" color="success" :sub="$channelSub($kpi['month'])" />
        <x-ui.stat label="Fallidos (mes)" :value="number_format($kpi['failed'])" icon="exclamation-triangle" color="danger" :sub="$kpi['failed_rate'].'% del total del mes'" />
        <x-ui.stat label="En cola" :value="number_format($kpi['queued'])" icon="clock" color="navy" sub="Se entregan cada minuto" />
    </div>

    {{-- Filtro rápido por canal --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterChannel', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterChannel === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterChannel !== ''])>
            Todos los canales
        </button>
        @foreach ($channels as $key => $label)
            <button wire:click="$set('filterChannel', '{{ $key }}')" @class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterChannel === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterChannel !== $key])>
                <x-dynamic-component :component="'heroicon-o-'.MC::CHANNEL_ICONS[$key]" class="h-3.5 w-3.5" /> {{ $label }}
                <span class="opacity-70">{{ number_format($kpi['month'][$key] ?? 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-6">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Cliente, código o destino..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterStatus" placeholder="Todos los estados">
                <option value="sent">Enviados (todos)</option>
                @foreach (\App\Models\Message::STATUS as $k => [$l])
                    <option value="{{ $k }}">{{ $l }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="filterEvent" :options="$events" placeholder="Todos los eventos" />
            <x-ui.input type="date" wire:model.live="filterFrom" title="Desde" />
            <div class="flex gap-2">
                <x-ui.input type="date" wire:model.live="filterTo" title="Hasta" class="flex-1" />
                @if ($search || $filterChannel || $filterStatus || $filterEvent || $filterFrom || $filterTo)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                @endif
            </div>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th sort="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th>
                <th>Destinatario</th>
                <x-ui.th sort="channel" :sort-by="$sortBy" :sort-dir="$sortDir">Canal</x-ui.th>
                <th>Evento</th>
                <th>Mensaje</th>
                <x-ui.th sort="status" :sort-by="$sortBy" :sort-dir="$sortDir">Estado</x-ui.th>
                <th></th>
            </x-slot:head>
            @forelse ($messagesList as $m)
                <tr wire:key="m{{ $m->id }}" class="cursor-pointer" wire:click="openDetail({{ $m->id }})">
                    <td class="whitespace-nowrap text-xs text-slate-600">
                        <div class="font-medium text-slate-700">{{ $m->created_at->format('d/m/Y') }}</div>
                        <div>{{ $m->created_at->format('H:i') }}</div>
                    </td>
                    <td>
                        @if ($m->client)
                            <div class="font-semibold text-slate-800">{{ $m->client->full_name }}</div>
                            <div class="text-xs text-slate-500">#{{ $m->client->code }} · {{ $m->to }}</div>
                        @else
                            <div class="text-sm text-slate-700">{{ $m->to }}</div>
                            <div class="text-xs text-slate-400">Sin cliente</div>
                        @endif
                    </td>
                    <td>
                        <x-ui.badge :color="MC::CHANNEL_COLORS[$m->channel] ?? 'slate'">
                            <x-dynamic-component :component="'heroicon-o-'.(MC::CHANNEL_ICONS[$m->channel] ?? 'chat-bubble-left')" class="h-3.5 w-3.5" />
                            {{ $channels[$m->channel] ?? $m->channel }}
                        </x-ui.badge>
                    </td>
                    <td class="text-xs text-slate-600">
                        {{ MC::eventLabel($m->event) }}
                        @if ($m->campaign)<div class="max-w-[160px] truncate text-slate-400">{{ $m->campaign->name }}</div>@endif
                    </td>
                    <td class="max-w-[340px]"><div class="truncate text-sm text-slate-600">{{ $m->body }}</div></td>
                    <td>
                        <x-ui.badge :color="MC::statusColor($m->status)" dot>{{ MC::statusLabel($m->status) }}</x-ui.badge>
                        @if ($m->status === 'failed' && $m->error)<div class="mt-0.5 max-w-[160px] truncate text-[11px] text-danger-600" title="{{ $m->error }}">{{ $m->error }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap text-right" @click.stop>
                        @if ($m->status === 'failed')
                            <x-ui.button size="sm" variant="secondary" icon="arrow-path" wire:click="resend({{ $m->id }})">Reenviar</x-ui.button>
                        @endif
                        @if ($m->client_id)
                            <x-ui.button size="sm" variant="ghost" icon="chat-bubble-left-right" wire:click="openChat({{ $m->client_id }})" title="Conversación" />
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="chat-bubble-left-right" title="No hay mensajes" description="Ajusta los filtros o envía un nuevo mensaje." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $messagesList->links() }}</div>
    </x-ui.card>

    {{-- Detalle (panel lateral) --}}
    <div x-data="{ open: @entangle('showDetail').live }" x-show="open" x-cloak class="fixed inset-0 z-50" x-on:keydown.escape.window="open = false">
        <div x-show="open" x-transition.opacity class="fixed inset-0 bg-navy-950/40" @click="open = false"></div>
        <div x-show="open" x-transition:enter="transform transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="text-base font-bold text-navy-900">Detalle del mensaje</h3>
                <button type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100" @click="open = false"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
            </div>
            @if ($detail)
                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-5">
                    <div class="flex items-center gap-2">
                        <x-ui.badge :color="MC::CHANNEL_COLORS[$detail->channel] ?? 'slate'">{{ $channels[$detail->channel] ?? $detail->channel }}</x-ui.badge>
                        <x-ui.badge :color="MC::statusColor($detail->status)" dot>{{ MC::statusLabel($detail->status) }}</x-ui.badge>
                        <span class="text-xs text-slate-500">#{{ $detail->id }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-4">
                        <x-ui.detail label="Cliente">
                            @if ($detail->client)<a href="{{ route('clients.show', $detail->client) }}" wire:navigate class="link">{{ $detail->client->full_name }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="Destino">{{ $detail->to }}</x-ui.detail>
                        <x-ui.detail label="Evento">{{ MC::eventLabel($detail->event) }}</x-ui.detail>
                        <x-ui.detail label="Campaña">
                            @if ($detail->campaign)<a href="{{ route('communications.campaigns.show', $detail->campaign) }}" wire:navigate class="link">{{ $detail->campaign->name }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="Creado">{{ $detail->created_at->format('d/m/Y H:i:s') }}</x-ui.detail>
                        <x-ui.detail label="Enviado">{{ $detail->sent_at?->format('d/m/Y H:i:s') }}</x-ui.detail>
                        <x-ui.detail label="ID proveedor" class="col-span-2"><span class="font-mono text-xs">{{ $detail->provider_id }}</span></x-ui.detail>
                        @if ($detail->subject)<x-ui.detail label="Asunto" class="col-span-2">{{ $detail->subject }}</x-ui.detail>@endif
                    </dl>
                    <div>
                        <div class="mb-1.5 text-xs font-medium text-slate-500">Mensaje</div>
                        <div class="whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm text-slate-700 ring-1 ring-slate-100">{{ $detail->body }}</div>
                    </div>
                    @if ($detail->error)
                        <div class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700"><span class="font-semibold">Error:</span> {{ $detail->error }}</div>
                    @endif
                    @if ($detail->status === 'simulated')
                        <p class="text-xs text-slate-500">Modo simulado: el mensaje quedó registrado pero no se envió a un proveedor real. Configura WhatsApp/SMS en la configuración de la empresa.</p>
                    @endif
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                    @if ($detail->client_id)
                        <x-ui.button variant="secondary" icon="chat-bubble-left-right" wire:click="openChat({{ $detail->client_id }})">Conversación</x-ui.button>
                    @endif
                    @if ($detail->status === 'failed')
                        <x-ui.button icon="arrow-path" wire:click="resend({{ $detail->id }})">Reenviar</x-ui.button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- Conversación tipo WhatsApp --}}
    <div x-data="{ open: @entangle('showChat').live, scroll() { this.$nextTick(() => { const el = this.$refs.thread; if (el) el.scrollTop = el.scrollHeight }) } }"
         x-init="$watch('open', v => v && scroll())" x-on:chat-sent.window="scroll()" x-show="open" x-cloak class="fixed inset-0 z-50" x-on:keydown.escape.window="open = false">
        <div x-show="open" x-transition.opacity class="fixed inset-0 bg-navy-950/40" @click="open = false"></div>
        <div x-show="open" x-transition:enter="transform transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="fixed inset-y-0 right-0 flex w-full max-w-lg flex-col bg-white shadow-2xl">
            @if ($chatClient)
                <div class="flex items-center gap-3 bg-[#075E54] px-4 py-3 text-white">
                    <x-ui.avatar :name="$chatClient->full_name" color="success" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold">{{ $chatClient->full_name }}</div>
                        <div class="truncate text-xs text-white/75">#{{ $chatClient->code }} · {{ $chatClient->whatsapp ?: $chatClient->phone ?: 'Sin teléfono' }}</div>
                    </div>
                    <a href="{{ route('clients.show', $chatClient) }}" wire:navigate class="rounded-lg p-1.5 hover:bg-white/10" title="Ver cliente"><x-heroicon-o-user class="h-5 w-5" /></a>
                    <button type="button" class="rounded-lg p-1.5 hover:bg-white/10" @click="open = false"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
                </div>
                <div x-ref="thread" class="flex-1 space-y-2 overflow-y-auto bg-[#ECE5DD] px-4 py-4">
                    @php $lastDay = null; @endphp
                    @forelse ($chat as $m)
                        @if ($lastDay !== $m->created_at->toDateString())
                            @php $lastDay = $m->created_at->toDateString(); @endphp
                            <div class="flex justify-center py-1"><span class="rounded-lg bg-white/80 px-2.5 py-0.5 text-[11px] font-medium text-slate-600 shadow-xs">{{ $m->created_at->translatedFormat('d \d\e F Y') }}</span></div>
                        @endif
                        <div class="flex justify-end" wire:key="b{{ $m->id }}">
                            <div @class(['relative max-w-[82%] rounded-xl rounded-tr-sm px-3 py-2 text-sm shadow-xs', 'bg-[#D9FDD3]' => $m->status !== 'failed', 'bg-danger-50 ring-1 ring-danger-100' => $m->status === 'failed'])>
                                @if ($m->channel !== 'whatsapp' || $m->event)
                                    <div class="mb-0.5 text-[10.5px] font-semibold text-teal-700">{{ $channels[$m->channel] ?? $m->channel }} · {{ MC::eventLabel($m->event) }}</div>
                                @endif
                                <div class="whitespace-pre-line text-slate-800">{{ $m->body }}</div>
                                <div class="mt-1 flex items-center justify-end gap-1 text-[10.5px] text-slate-500">
                                    {{ $m->created_at->format('H:i') }}
                                    @if ($m->status === 'failed')
                                        <x-heroicon-m-exclamation-circle class="h-3.5 w-3.5 text-danger-600" title="{{ $m->error }}" />
                                    @elseif ($m->status === 'queued')
                                        <x-heroicon-m-clock class="h-3.5 w-3.5" />
                                    @else
                                        <span @class(['font-bold tracking-[-3px]', 'text-sky-500' => $m->status === 'delivered'])>✓✓</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="py-16 text-center text-sm text-slate-500">Aún no hay mensajes con este cliente.</div>
                    @endforelse
                </div>
                <form wire:submit="sendChat" class="border-t border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-end gap-2">
                        <select wire:model="chatChannel" class="form-input w-28 shrink-0 py-2 text-xs">
                            @foreach ($channels as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                        </select>
                        <textarea wire:model="chatBody" rows="2" maxlength="1600" class="form-input flex-1 resize-none" placeholder="Escribe un mensaje... puedes usar {nombre}, {saldo}, {link_pago}"></textarea>
                        <button type="submit" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#00A884] text-white shadow hover:brightness-105" title="Enviar">
                            <x-heroicon-s-paper-airplane class="h-5 w-5" wire:loading.remove wire:target="sendChat" />
                            <svg wire:loading wire:target="sendChat" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                    @error('chatBody')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                </form>
            @endif
        </div>
    </div>

    {{-- Nuevo mensaje --}}
    <x-ui.modal wire:model="showCompose" title="Nuevo mensaje" size="3xl">
        <div class="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,1fr)_260px]">
            <div class="space-y-4">
                {{-- Cliente --}}
                <div>
                    <label class="form-label">Cliente <span class="text-danger-600">*</span></label>
                    @if ($previewClient)
                        <div class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                            <x-ui.avatar :name="$previewClient->full_name" size="sm" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-slate-800">{{ $previewClient->full_name }}</div>
                                <div class="truncate text-xs text-slate-500">#{{ $previewClient->code }} · {{ $previewClient->whatsapp ?: $previewClient->phone }} {{ $previewClient->email ? '· '.$previewClient->email : '' }}</div>
                            </div>
                            <x-ui.button size="sm" variant="ghost" icon="x-mark" wire:click="$set('compose.client_id', null)" title="Cambiar cliente" />
                        </div>
                    @else
                        <div class="relative">
                            <x-ui.search wire:model.live.debounce.300ms="clientSearch" placeholder="Busca por nombre, código o teléfono..." />
                            @if ($clientResults->isNotEmpty())
                                <div class="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                                    @foreach ($clientResults as $c)
                                        <button type="button" wire:click="selectClient({{ $c->id }})" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50">
                                            <x-ui.avatar :name="$c->full_name" size="sm" />
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-medium text-slate-800">{{ $c->full_name }}</span>
                                                <span class="block truncate text-xs text-slate-500">#{{ $c->code }} · {{ $c->phone }}</span>
                                            </span>
                                            <x-ui.status catalog="client_status" :value="$c->service_status" size="xs" />
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (strlen(trim($clientSearch)) >= 2)
                                <p class="mt-1 text-xs text-slate-500">Sin coincidencias.</p>
                            @endif
                        </div>
                    @endif
                    @error('compose.client_id')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.select label="Canal" wire:model.live="compose.channel" :options="$channels" />
                    <div>
                        <label class="form-label">Contenido</label>
                        <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
                            @foreach (['template' => 'Plantilla', 'free' => 'Texto libre'] as $k => $l)
                                <button type="button" wire:click="$set('compose.mode', '{{ $k }}')" @class(['rounded-md px-2 py-1.5 text-xs font-semibold transition', 'bg-white text-navy-900 shadow-xs' => $compose['mode'] === $k, 'text-slate-500 hover:text-slate-700' => $compose['mode'] !== $k])>{{ $l }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if ($compose['mode'] === 'template')
                    <x-ui.select label="Plantilla (evento)" wire:model.live="compose.event" :options="config('wisp.message_events')" hint="Se carga el texto de la plantilla del WISP; puedes ajustarlo antes de enviar." />
                @endif
                @if (in_array($compose['channel'], ['email', 'push']))
                    <x-ui.input label="Asunto / título" wire:model.live.debounce.400ms="compose.subject" maxlength="150" />
                @endif

                <div x-data="{ insert(v) { const t = this.$refs.body; const s = t.selectionStart ?? t.value.length; t.setRangeText(v, s, t.selectionEnd ?? s, 'end'); t.dispatchEvent(new Event('input')); t.focus(); } }">
                    <x-ui.textarea label="Mensaje" wire:model.live.debounce.400ms="compose.body" x-ref="body" rows="5" maxlength="1600" required />
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach (\App\Models\NotificationTemplate::VARIABLES as $v)
                            <button type="button" @click="insert('{{ $v }}')" class="rounded-md bg-brand-50 px-1.5 py-0.5 font-mono text-[11px] text-brand-700 ring-1 ring-brand-100 hover:bg-brand-100">{{ $v }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Vista previa --}}
            <div>
                <div class="form-label">Vista previa</div>
                <div class="rounded-2xl bg-[#ECE5DD] p-3">
                    @if ($preview)
                        <div class="ml-auto max-w-[95%] rounded-xl rounded-tr-sm bg-[#D9FDD3] px-3 py-2 text-sm shadow-xs">
                            @if ($compose['subject'] && in_array($compose['channel'], ['email', 'push']))<div class="mb-1 font-semibold text-slate-800">{{ $compose['subject'] }}</div>@endif
                            <div class="whitespace-pre-line break-words text-slate-800">{{ $preview }}</div>
                            <div class="mt-1 text-right text-[10.5px] text-slate-500">{{ now()->format('H:i') }} ✓✓</div>
                        </div>
                    @else
                        <p class="py-8 text-center text-xs text-slate-500">Escribe un mensaje para ver la vista previa.</p>
                    @endif
                </div>
                <p class="mt-2 text-[11px] text-slate-500">
                    {{ mb_strlen((string) $preview) }} caracteres
                    @if ($compose['channel'] === 'sms') · {{ max(1, (int) ceil(mb_strlen((string) $preview) / 160)) }} SMS @endif
                    @unless ($previewClient) · selecciona un cliente para ver sus datos @endunless
                </p>
                @error('compose.channel')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="paper-airplane" wire:click="send">Enviar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
