<div>
    <x-ui.page-header title="Reportes" subtitle="Consulta, filtra y exporta la información de tu WISP a Excel, CSV o PDF">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button icon="arrow-down-tray">Exportar {{ mb_strtolower($def['label']) }}</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF con resumen</x-ui.dropdown-item>
        </x-ui.dropdown>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[250px_minmax(0,1fr)]">
        {{-- Catálogo --}}
        <x-ui.card :padding="false" class="self-start">
            <div class="max-h-[70vh] overflow-y-auto p-2 lg:max-h-none">
                @foreach ($catalog as $group => $items)
                    <div class="px-3 pt-3 pb-1 text-[10.5px] font-semibold tracking-wider text-slate-400 uppercase">{{ $group }}</div>
                    @foreach ($items as $key => $r)
                        <button type="button" wire:click="select('{{ $key }}')" wire:key="rep-{{ $key }}" @class([
                            'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm transition',
                            'bg-brand-50 font-semibold text-brand-700' => $report === $key,
                            'text-slate-700 hover:bg-slate-50' => $report !== $key,
                        ])>
                            <x-dynamic-component :component="'heroicon-o-'.$r['icon']" class="h-[18px] w-[18px] shrink-0 opacity-80" />
                            {{ $r['label'] }}
                        </button>
                    @endforeach
                @endforeach
            </div>
        </x-ui.card>

        <div class="min-w-0 space-y-5">
            <x-ui.card>
                <div class="mb-4 flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                        <x-dynamic-component :component="'heroicon-o-'.$def['icon']" class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-navy-900">Reporte de {{ mb_strtolower($def['label']) }}</h2>
                        <p class="text-sm text-slate-500">{{ $def['description'] }}</p>
                    </div>
                </div>

                @if ($def['filters'])
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        @if (in_array('date', $def['filters']))
                            <x-ui.input type="date" :label="($def['date_label'] ?? 'Fecha').' desde'" wire:model.live.debounce.500ms="f.from" />
                            <x-ui.input type="date" label="Hasta" wire:model.live.debounce.500ms="f.to" />
                        @endif
                        @if (in_array('zone', $def['filters']))
                            <x-ui.select label="Zona" wire:model.live="f.zone_id" :options="$zones" placeholder="Todas" />
                        @endif
                        @if (in_array('plan', $def['filters']))
                            <x-ui.select label="Plan" wire:model.live="f.plan_id" :options="$plans" placeholder="Todos" />
                        @endif
                        @if (in_array('status', $def['filters']))
                            <x-ui.select :label="$def['status_label'] ?? 'Estado'" wire:model.live="f.status" :options="$def['status']" placeholder="Todos" />
                        @endif
                        @if (in_array('type', $def['filters']))
                            <x-ui.select :label="$def['type_label'] ?? 'Tipo'" wire:model.live="f.type" :options="$def['type']" placeholder="Todos" />
                        @endif
                        <div class="flex items-end">
                            <x-ui.button variant="ghost" icon="arrow-path" wire:click="resetFilters">Restablecer</x-ui.button>
                        </div>
                    </div>
                @endif

                @if ($error)
                    <div class="mt-4 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ $error }}</div>
                @endif

                @if ($summary)
                    <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-{{ min(5, count($summary)) }}">
                        @foreach ($summary as $label => $value)
                            <div class="rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                                <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
                                <div class="mt-0.5 text-lg font-bold text-navy-900">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card :padding="false">
                <x-slot:title>Vista previa</x-slot:title>
                <x-slot:actions>
                    <span class="text-xs text-slate-500" wire:loading.remove>
                        {{ number_format($total) }} registros{{ $total > \App\Livewire\Reports\Index::PREVIEW ? ' · mostrando los primeros '.\App\Livewire\Reports\Index::PREVIEW : '' }}
                    </span>
                    <span class="text-xs text-brand-600" wire:loading>Cargando…</span>
                </x-slot:actions>
                <div class="relative" wire:loading.class="opacity-60">
                    <x-ui.table>
                        <x-slot:head>
                            @foreach ($preview['headers'] as $h)<th class="whitespace-nowrap">{{ $h }}</th>@endforeach
                        </x-slot:head>
                        @forelse ($preview['rows'] as $i => $row)
                            <tr wire:key="row-{{ $report }}-{{ $i }}">
                                @foreach ($row as $cell)
                                    <td @class(['whitespace-nowrap text-xs', 'text-right tabular-nums' => is_int($cell) || is_float($cell)])>
                                        {{ is_float($cell) ? number_format($cell, 2) : ($cell === null || $cell === '' ? '—' : $cell) }}
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <x-ui.empty :colspan="max(1, count($preview['headers']))" icon="document-magnifying-glass" title="Sin registros" description="No hay información con los filtros seleccionados." />
                        @endforelse
                    </x-ui.table>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
