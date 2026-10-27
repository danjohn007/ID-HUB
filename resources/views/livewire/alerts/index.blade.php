@php
    use App\Services\Automation\RuleCatalog;
    $sevIcon = ['critical' => 'exclamation-triangle', 'warning' => 'exclamation-circle', 'info' => 'information-circle'];
    $sevBg = ['critical' => 'bg-danger-50 text-danger-600', 'warning' => 'bg-warning-50 text-warning-600', 'info' => 'bg-brand-50 text-brand-600'];
    $statusMap = ['open' => ['Abierta', 'danger'], 'acknowledged' => ['Atendida', 'warning'], 'resolved' => ['Resuelta', 'success']];
@endphp
<div>
    <x-ui.page-header title="Alertas inteligentes" subtitle="Red, cobranza, soporte, inventario y crecimiento: todo lo que requiere tu atención">
        <x-ui.button variant="secondary" icon="sparkles" wire:click="analyze">Analizar ahora</x-ui.button>
        @if (Route::has('automations.index'))
            @can('automations.manage')<x-ui.button variant="secondary" icon="bolt" :href="route('automations.index')">Automatizaciones</x-ui.button>@endcan
        @endif
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div class="grid grid-cols-2 gap-3">
            <button type="button" wire:click="$set('filterSeverity', 'critical')" class="text-left"><x-ui.stat label="Críticas abiertas" :value="$openBySeverity['critical'] ?? 0" icon="exclamation-triangle" color="danger" sub="Requieren acción inmediata" /></button>
            <button type="button" wire:click="$set('filterSeverity', 'warning')" class="text-left"><x-ui.stat label="Advertencias" :value="$openBySeverity['warning'] ?? 0" icon="exclamation-circle" color="warning" sub="Abiertas o en atención" /></button>
            <button type="button" wire:click="$set('filterSeverity', 'info')" class="text-left"><x-ui.stat label="Informativas" :value="$openBySeverity['info'] ?? 0" icon="information-circle" color="brand" sub="Oportunidades y avisos" /></button>
            <button type="button" wire:click="$set('filterStatus', 'resolved')" class="text-left"><x-ui.stat label="Resueltas (7 días)" :value="$resolvedWeek" icon="check-circle" color="success" sub="Manual o automáticamente" /></button>
        </div>
        <x-ui.card title="Alertas por día" subtitle="Últimos 14 días">
            <x-ui.chart id="alerts-trend" :options="$chart" height="170px" />
        </x-ui.card>
    </div>

    {{-- Agrupación por tipo --}}
    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterType', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterType === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterType !== ''])>
            Todos los tipos <span class="ml-1 opacity-70">{{ $openByType->sum() }}</span>
        </button>
        @foreach ($openByType as $type => $n)
            <button wire:click="$set('filterType', '{{ $type }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterType === $type, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterType !== $type])>
                {{ RuleCatalog::alertTypeLabel($type) }} <span class="ml-1 opacity-70">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-7">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar en alertas..." class="md:col-span-2" />
            <x-ui.select wire:model.live="filterType" :options="$types" placeholder="Todos los tipos" />
            <x-ui.select wire:model.live="filterSeverity" :options="config('wisp.alert_severity')" placeholder="Toda severidad" />
            <x-ui.select wire:model.live="filterStatus">
                <option value="open">Abiertas y atendidas</option>
                <option value="acknowledged">Solo atendidas</option>
                <option value="resolved">Resueltas</option>
                <option value="all">Todas</option>
            </x-ui.select>
            <x-ui.input type="date" wire:model.live="filterFrom" title="Desde" />
            <div class="flex gap-2">
                <x-ui.input type="date" wire:model.live="filterTo" title="Hasta" class="flex-1" />
                @if ($search || $filterType || $filterSeverity || $filterFrom || $filterTo || $filterStatus !== 'open')
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
            <x-ui.toggle wire:model.live="grouped" label="Agrupar por tipo" />
            @if (count($selected))
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-500">{{ count($selected) }} seleccionadas</span>
                    <x-ui.button size="sm" variant="secondary" icon="eye" wire:click="acknowledgeSelected">Marcar atendidas</x-ui.button>
                    <x-ui.button size="sm" variant="success" icon="check" wire:click="resolveSelected" wire:confirm="¿Resolver las alertas seleccionadas?">Resolver seleccionadas</x-ui.button>
                </div>
            @endif
        </div>

        @php
            $sections = $grouped ? $groups : collect(['' => $alerts->getCollection()]);
        @endphp
        <div x-data="{ toggleAll(e) { $wire.set('selected', e.target.checked ? @js($alerts->getCollection()->where('status', '!=', 'resolved')->pluck('id')->map(fn ($i) => (string) $i)->values()) : []) } }">
            <x-ui.table>
                <x-slot:head>
                    <th class="w-8"><input type="checkbox" class="rounded border-slate-300" @change="toggleAll($event)" title="Seleccionar página"></th>
                    <x-ui.th sort="severity" :sort-by="$sortBy" :sort-dir="$sortDir">Alerta</x-ui.th>
                    <x-ui.th sort="type" :sort-by="$sortBy" :sort-dir="$sortDir">Tipo</x-ui.th>
                    <th>Estado</th>
                    <x-ui.th sort="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th>
                    <th></th>
                </x-slot:head>
                @forelse ($sections as $type => $items)
                    @if ($grouped)
                        <tr wire:key="g-{{ $type }}" class="bg-slate-50/80"><td colspan="6" class="py-2 text-xs font-bold tracking-wide text-slate-600 uppercase">{{ RuleCatalog::alertTypeLabel($type) }} <span class="ml-1 font-semibold text-slate-400">{{ $items->count() }}</span></td></tr>
                    @endif
                    @foreach ($items as $a)
                        @php $url = $a->subjectUrl(); [$stL, $stC] = $statusMap[$a->status] ?? [$a->status, 'slate']; @endphp
                        <tr wire:key="a{{ $a->id }}" @class(['opacity-60' => $a->status === 'resolved'])>
                            <td>@if ($a->status !== 'resolved')<input type="checkbox" value="{{ $a->id }}" wire:model.live="selected" class="rounded border-slate-300">@endif</td>
                            <td>
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $sevBg[$a->severity] ?? 'bg-slate-100 text-slate-500' }}">
                                        <x-dynamic-component :component="'heroicon-o-'.($sevIcon[$a->severity] ?? 'bell')" class="h-4.5 w-4.5" />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-800">{{ $a->title }}</div>
                                        @if ($a->message)<div class="max-w-[520px] text-xs text-slate-500">{{ $a->message }}</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="text-xs font-medium text-slate-700">{{ RuleCatalog::alertTypeLabel($a->type) }}</div>
                                <x-ui.status catalog="alert_severity" :value="$a->severity" size="xs" :dot="false" />
                            </td>
                            <td>
                                <x-ui.badge :color="$stC" dot>{{ $stL }}</x-ui.badge>
                                @if ($a->acknowledgedBy)<div class="mt-0.5 text-2xs text-slate-500">{{ $a->acknowledgedBy->name }}</div>@endif
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-600">
                                <div>{{ $a->created_at->format('d/m/Y H:i') }}</div>
                                <div class="text-slate-400">{{ $a->status === 'resolved' && $a->resolved_at ? 'Resuelta '.$a->resolved_at->diffForHumans() : $a->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($url)<x-ui.button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$url" title="Ver relacionado" />@endif
                                @if ($a->status === 'open')
                                    <x-ui.button size="sm" variant="ghost" icon="eye" wire:click="acknowledge({{ $a->id }})" title="Marcar como atendida" />
                                @endif
                                @if ($a->status !== 'resolved')
                                    <x-ui.button size="sm" variant="secondary" icon="check" wire:click="resolve({{ $a->id }})">Resolver</x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="reopen({{ $a->id }})" title="Reabrir" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @empty
                    <x-ui.empty :colspan="6" icon="bell-slash" title="Sin alertas" description="No hay alertas con estos filtros. ¡Todo en orden!" />
                @endforelse
                @if ($grouped && $alerts->isEmpty())
                    <x-ui.empty :colspan="6" icon="bell-slash" title="Sin alertas" description="No hay alertas con estos filtros." />
                @endif
            </x-ui.table>
        </div>
        <div class="border-t border-slate-100 px-4 py-3">{{ $alerts->links() }}</div>
    </x-ui.card>
</div>
