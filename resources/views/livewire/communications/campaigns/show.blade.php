@php
    use App\Services\Messaging\MessageCatalog as MC;
    [$stLabel, $stColor] = \App\Models\Campaign::STATUS[$campaign->status] ?? [$campaign->status, 'slate'];
@endphp
<div @if ($campaign->status === 'sending') wire:poll.3s="tick" @endif>
    <x-ui.page-header :title="$campaign->name" :breadcrumbs="['Campañas' => route('communications.campaigns'), $campaign->name => null]">
        <x-ui.button variant="secondary" icon="document-duplicate" wire:click="duplicate">Duplicar</x-ui.button>
        @if (in_array($campaign->status, ['draft', 'scheduled']))
            <x-ui.button variant="secondary" icon="pencil" :href="route('communications.campaigns.edit', $campaign)">Editar</x-ui.button>
        @endif
        @if (in_array($campaign->status, ['draft', 'scheduled', 'sending']))
            <x-ui.button variant="secondary" icon="x-mark" wire:click="cancel" wire:confirm="¿Cancelar la campaña? Los mensajes ya enviados no se revierten.">Cancelar</x-ui.button>
        @endif
        @if ($campaign->failed_count > 0 && $hasMessages)
            <x-ui.button variant="warning" icon="arrow-path" wire:click="retryFailed">Reintentar fallidos</x-ui.button>
        @endif
        @if (in_array($campaign->status, ['draft', 'scheduled']))
            <x-ui.button icon="paper-airplane" wire:click="sendNow" wire:confirm="¿Enviar la campaña ahora?">Enviar ahora</x-ui.button>
        @endif
    </x-ui.page-header>

    @if ($campaign->status === 'scheduled')
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-purple-100 bg-purple-50 px-4 py-3 text-sm text-purple-800">
            <x-heroicon-o-calendar-days class="h-5 w-5" />
            Programada para el <b>{{ $campaign->scheduled_at?->format('d/m/Y H:i') }}</b> ({{ $campaign->scheduled_at?->diffForHumans() }}). Se enviará automáticamente.
        </div>
    @elseif ($campaign->status === 'sending')
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-warning-100 bg-warning-50 px-4 py-3 text-sm text-warning-800">
            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Enviando por lotes... {{ number_format($pending) }} destinatarios pendientes. Puedes salir de esta página; el envío continúa cada minuto.
        </div>
    @endif

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat variant="soft" label="Destinatarios" :value="number_format($campaign->recipients_count)" icon="users" color="brand" :sub="$segmentLabel" />
        <x-ui.stat variant="soft" label="Enviados" :value="number_format($campaign->sent_count)" icon="check-circle" color="success"
                   :sub="$campaign->recipients_count ? round($campaign->sent_count / $campaign->recipients_count * 100, 1).'% de efectividad' : '—'" />
        <x-ui.stat variant="soft" label="Fallidos" :value="number_format($campaign->failed_count)" icon="x-circle" color="danger" />
        <x-ui.stat variant="soft" label="En cola" :value="number_format($queued + $pending)" icon="clock" color="slate" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card title="Progreso">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <x-ui.badge :color="$stColor" dot>{{ $stLabel }}</x-ui.badge>
                    <span class="font-semibold text-slate-700">{{ $progress }}%</span>
                </div>
                @php $tot = max(1, $campaign->recipients_count); @endphp
                <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-3 bg-success-600 transition-all" style="width: {{ $campaign->sent_count / $tot * 100 }}%"></div>
                    <div class="h-3 bg-danger-600 transition-all" style="width: {{ $campaign->failed_count / $tot * 100 }}%"></div>
                    <div class="h-3 bg-slate-300 transition-all" style="width: {{ $queued / $tot * 100 }}%"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-success-600"></span>Enviados {{ number_format($campaign->sent_count) }}</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-danger-600"></span>Fallidos {{ number_format($campaign->failed_count) }}</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-slate-300"></span>En cola {{ number_format($queued + $pending) }}</span>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false" title="Destinatarios">
                <x-slot:actions>
                    <div class="flex gap-2">
                        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar..." class="w-44" />
                        <x-ui.select wire:model.live="filterStatus" placeholder="Todos">
                            <option value="sent">Enviados</option>
                            <option value="failed">Fallidos</option>
                            <option value="queued">En cola</option>
                        </x-ui.select>
                    </div>
                </x-slot:actions>
                <x-ui.table>
                    <x-slot:head><th>Cliente</th><th>Destino</th><th>Estado</th><th>Enviado</th></x-slot:head>
                    @forelse ($messages as $m)
                        <tr wire:key="cm{{ $m->id }}">
                            <td>
                                @if ($m->client)
                                    <a href="{{ route('clients.show', $m->client) }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-600">{{ $m->client->full_name }}</a>
                                    <div class="text-xs text-slate-500">#{{ $m->client->code }}</div>
                                @else — @endif
                            </td>
                            <td class="text-xs text-slate-600">{{ $m->to }}</td>
                            <td>
                                <x-ui.badge :color="MC::statusColor($m->status)" dot>{{ MC::statusLabel($m->status) }}</x-ui.badge>
                                @if ($m->error)<div class="mt-0.5 max-w-[220px] truncate text-2xs text-danger-600" title="{{ $m->error }}">{{ $m->error }}</div>@endif
                            </td>
                            <td class="text-xs text-slate-600">{{ $m->sent_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="4" icon="users" :title="$campaign->recipients_count && ! $hasMessages && $campaign->status === 'sent' ? 'Campaña histórica sin detalle por destinatario' : 'Sin mensajes'"
                                    :description="in_array($campaign->status, ['draft', 'scheduled']) ? 'Los destinatarios aparecerán al enviar la campaña.' : null" />
                    @endforelse
                </x-ui.table>
                @if ($messages->hasPages())<div class="border-t border-slate-100 px-4 py-3">{{ $messages->links() }}</div>@endif
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card title="Mensaje">
                <div class="rounded-2xl bg-[#ECE5DD] p-3">
                    <div class="ml-auto max-w-[95%] rounded-xl rounded-tr-sm bg-[#D9FDD3] px-3 py-2 text-sm shadow-xs">
                        @if ($campaign->subject)<div class="mb-1 font-semibold text-slate-800">{{ $campaign->subject }}</div>@endif
                        <div class="whitespace-pre-line break-words text-slate-800">{{ $campaign->body }}</div>
                    </div>
                </div>
            </x-ui.card>
            <x-ui.card title="Datos de la campaña">
                <dl class="grid grid-cols-2 gap-4">
                    <x-ui.detail label="Canal">{{ config('wisp.channels.'.$campaign->channel, $campaign->channel) }}</x-ui.detail>
                    <x-ui.detail label="Segmento">{{ $segmentLabel }}</x-ui.detail>
                    <x-ui.detail label="Creada">{{ $campaign->created_at?->format('d/m/Y H:i') }}</x-ui.detail>
                    <x-ui.detail label="Creada por">{{ $campaign->createdBy?->name }}</x-ui.detail>
                    <x-ui.detail label="Programada">{{ $campaign->scheduled_at?->format('d/m/Y H:i') }}</x-ui.detail>
                    <x-ui.detail label="Envío">{{ $campaign->sent_at?->format('d/m/Y H:i') }}</x-ui.detail>
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
