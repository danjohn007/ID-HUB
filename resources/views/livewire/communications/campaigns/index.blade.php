@php
    use App\Services\Messaging\MessageCatalog as MC;
    $statuses = \App\Models\Campaign::STATUS;
@endphp
<div>
    <x-ui.page-header title="Campañas" subtitle="Notificaciones masivas por zona, repetidor, plan o estado del cliente">
        <x-ui.button icon="plus" :href="route('communications.campaigns.create')">Nueva campaña</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat label="Campañas este mes" :value="number_format($kpi['sent_month'])" icon="megaphone" color="brand" :sub="number_format($kpi['recipients_month']).' destinatarios'" />
        <x-ui.stat label="Mensajes entregados" :value="number_format($kpi['delivered_month'])" icon="check-circle" color="success"
                   :sub="($kpi['recipients_month'] ? round($kpi['delivered_month'] / max(1, $kpi['recipients_month']) * 100, 1) : 0).'% de efectividad'" />
        <x-ui.stat label="Fallidos" :value="number_format($kpi['failed_month'])" icon="exclamation-triangle" color="danger" sub="Campañas de este mes" />
        <x-ui.stat label="Programadas" :value="$kpi['scheduled']" icon="calendar-days" color="purple"
                   :sub="$kpi['next'] ? 'Próxima: '.$kpi['next']->scheduled_at->format('d/m/Y H:i') : 'Sin envíos programados'" />
    </div>

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterStatus', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== ''])>
            Todas <span class="ml-1 opacity-70">{{ $counts->sum() }}</span>
        </button>
        @foreach ($statuses as $key => [$label])
            <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterStatus !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar campaña..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterChannel" :options="config('wisp.channels')" placeholder="Todos los canales" />
        </div>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th sort="name" :sort-by="$sortBy" :sort-dir="$sortDir">Campaña</x-ui.th>
                <th>Segmento</th>
                <th>Canal</th>
                <th>Estado</th>
                <x-ui.th sort="recipients_count" :sort-by="$sortBy" :sort-dir="$sortDir">Destinatarios</x-ui.th>
                <th class="min-w-[180px]">Resultado</th>
                <x-ui.th sort="sent_at" :sort-by="$sortBy" :sort-dir="$sortDir">Envío</x-ui.th>
                <th></th>
            </x-slot:head>
            @forelse ($campaigns as $c)
                @php $done = $c->sent_count + $c->failed_count; $pctOk = $c->recipients_count ? $c->sent_count / $c->recipients_count * 100 : 0; @endphp
                <tr wire:key="c{{ $c->id }}">
                    <td>
                        <a href="{{ route('communications.campaigns.show', $c) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $c->name }}</a>
                        <div class="max-w-[280px] truncate text-xs text-slate-500">{{ $c->body }}</div>
                    </td>
                    <td class="text-xs text-slate-600">{{ $service->segmentLabel($c) }}</td>
                    <td>
                        <x-ui.badge :color="MC::CHANNEL_COLORS[$c->channel] ?? 'slate'">
                            <x-dynamic-component :component="'heroicon-o-'.(MC::CHANNEL_ICONS[$c->channel] ?? 'chat-bubble-left')" class="h-3.5 w-3.5" />
                            {{ config('wisp.channels.'.$c->channel, $c->channel) }}
                        </x-ui.badge>
                    </td>
                    <td><x-ui.badge :color="$statuses[$c->status][1] ?? 'slate'" dot>{{ $statuses[$c->status][0] ?? $c->status }}</x-ui.badge></td>
                    <td class="font-semibold text-slate-700">{{ number_format($c->recipients_count) }}</td>
                    <td>
                        @if ($c->recipients_count)
                            <x-ui.progress :value="$pctOk" :color="$c->failed_count ? 'warning' : 'success'" />
                            <div class="mt-1 flex gap-3 text-[11px]">
                                <span class="text-success-700">{{ number_format($c->sent_count) }} enviados</span>
                                @if ($c->failed_count)<span class="text-danger-600">{{ number_format($c->failed_count) }} fallidos</span>@endif
                            </div>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-xs text-slate-600">
                        @if ($c->status === 'scheduled')
                            <span class="inline-flex items-center gap-1 text-purple-700"><x-heroicon-o-clock class="h-3.5 w-3.5" />{{ $c->scheduled_at?->format('d/m/Y H:i') }}</span>
                        @else
                            {{ $c->sent_at?->format('d/m/Y H:i') ?? '—' }}
                        @endif
                    </td>
                    <td class="text-right">
                        <x-ui.dropdown>
                            <x-slot:trigger><x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" /></x-slot:trigger>
                            <x-ui.dropdown-item icon="eye" :href="route('communications.campaigns.show', $c)">Ver resultados</x-ui.dropdown-item>
                            @if (in_array($c->status, ['draft', 'scheduled']))
                                <x-ui.dropdown-item icon="pencil" :href="route('communications.campaigns.edit', $c)">Editar</x-ui.dropdown-item>
                            @endif
                            <x-ui.dropdown-item icon="document-duplicate" wire:click="duplicate({{ $c->id }})">Duplicar</x-ui.dropdown-item>
                            @if (in_array($c->status, ['draft', 'cancelled']))
                                <x-ui.dropdown-item icon="trash" danger wire:click="delete({{ $c->id }})" wire:confirm="¿Eliminar la campaña {{ $c->name }}?">Eliminar</x-ui.dropdown-item>
                            @endif
                        </x-ui.dropdown>
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="8" icon="megaphone" title="No hay campañas" description="Crea una campaña para avisar a tus clientes de promociones, mantenimientos o cobranza.">
                    <x-ui.button icon="plus" :href="route('communications.campaigns.create')">Nueva campaña</x-ui.button>
                </x-ui.empty>
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $campaigns->links() }}</div>
    </x-ui.card>
</div>
