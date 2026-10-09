<div>
    <x-ui.page-header title="Auditoría" subtitle="Quién hizo qué, cuándo y desde dónde. Incluye valores anteriores y nuevos.">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-3 xl:grid-cols-7">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Texto, usuario, IP o ID del registro..." class="md:col-span-3 xl:col-span-2" />
            <x-ui.select wire:model.live="filterUser" :options="$users" placeholder="Todos los usuarios" />
            <x-ui.select wire:model.live="filterAction" :options="$actions" placeholder="Todas las acciones" />
            <x-ui.select wire:model.live="filterType" :options="$types" placeholder="Todos los registros" />
            <input type="date" wire:model.live="filterFrom" class="form-input" aria-label="Desde" title="Desde">
            <div class="flex gap-2">
                <input type="date" wire:model.live="filterTo" class="form-input flex-1" aria-label="Hasta" title="Hasta">
                @if ($search || $filterUser || $filterAction || $filterType || $filterFrom || $filterTo)
                    <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                @endif
            </div>
        </div>

        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-2.5 text-xs text-slate-500">
            <span>{{ number_format($logs->total()) }} eventos · {{ number_format($today) }} hoy</span>
            <span wire:loading class="text-brand-600">Buscando…</span>
        </div>

        <ul class="divide-y divide-slate-100">
            @forelse ($logs as $log)
                @php
                    $old = $log->old_values ?? [];
                    $new = $log->new_values ?? [];
                    $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
                @endphp
                <li wire:key="log{{ $log->id }}" x-data="{ open: false }" class="px-5 py-3.5">
                    <div class="flex items-start gap-3">
                        <x-ui.avatar :name="$log->user_name ?? 'Sistema'" size="sm" :color="$log->user_id ? 'brand' : 'navy'" class="mt-0.5" />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm text-slate-700">
                                Usuario <span class="font-bold text-navy-900 uppercase">{{ $log->user_name ?? 'Sistema' }}</span>
                                {{ $log->description }}
                                <span class="text-slate-400">·</span>
                                <span class="font-medium text-slate-500">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <x-ui.status catalog="audit_actions" :value="$log->action" size="xs" :dot="false" />
                                @if ($log->auditable_type)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-o-document class="h-3.5 w-3.5" />{{ \App\Livewire\Audit\Index::typeLabel($log->auditable_type) }} #{{ $log->auditable_id }}</span>
                                @endif
                                @if ($log->ip)
                                    <span class="inline-flex items-center gap-1"><x-heroicon-o-globe-alt class="h-3.5 w-3.5" />{{ $log->ip }}</span>
                                @endif
                                <span title="{{ $log->created_at?->format('d/m/Y H:i:s') }}">{{ $log->created_at?->diffForHumans() }}</span>
                                @if ($keys)
                                    <button type="button" @click="open = !open" class="inline-flex items-center gap-0.5 font-semibold text-brand-600 hover:text-brand-700">
                                        <span x-text="open ? 'Ocultar cambios' : 'Ver {{ count($keys) }} {{ count($keys) === 1 ? 'cambio' : 'cambios' }}'"></span>
                                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 transition" ::class="open && 'rotate-180'" />
                                    </button>
                                @endif
                            </div>
                            @if ($keys)
                                <div x-cloak x-show="open" x-transition class="mt-2 overflow-x-auto rounded-lg border border-slate-200">
                                    <table class="w-full text-xs">
                                        <thead class="bg-slate-50 text-left text-slate-500"><tr><th class="px-3 py-1.5 font-semibold">Campo</th><th class="px-3 py-1.5 font-semibold">Valor anterior</th><th class="px-3 py-1.5"></th><th class="px-3 py-1.5 font-semibold">Valor nuevo</th></tr></thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($keys as $k)
                                                <tr>
                                                    <td class="px-3 py-1.5 font-mono text-slate-600">{{ $k }}</td>
                                                    <td class="max-w-[320px] px-3 py-1.5 break-all text-danger-700"><span class="rounded bg-danger-50 px-1">{{ \Illuminate\Support\Str::limit(\App\Livewire\Audit\Index::show($old[$k] ?? null), 180) }}</span></td>
                                                    <td class="px-1 text-slate-400">→</td>
                                                    <td class="max-w-[320px] px-3 py-1.5 break-all text-success-700"><span class="rounded bg-success-50 px-1">{{ \Illuminate\Support\Str::limit(\App\Livewire\Audit\Index::show($new[$k] ?? null), 180) }}</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </li>
            @empty
                <li><x-ui.empty icon="shield-check" title="Sin eventos de auditoría" description="Ajusta los filtros. Los cambios a clientes, pagos, configuración y usuarios se registran automáticamente." /></li>
            @endforelse
        </ul>
        <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
    </x-ui.card>
</div>
