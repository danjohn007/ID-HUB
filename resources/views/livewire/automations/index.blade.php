@php use App\Services\Automation\RuleCatalog as RC; @endphp
<div>
    <x-ui.page-header title="Automatizaciones" subtitle="Motor de reglas: Evento → Condición → Acción">
        @if (Route::has('alerts.index'))<x-ui.button variant="secondary" icon="bell-alert" :href="route('alerts.index')">Alertas</x-ui.button>@endif
        <x-ui.button icon="plus" :href="route('automations.create')">Nueva regla</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat label="Reglas activas" :value="$kpi['active'].' / '.$kpi['total']" icon="bolt" color="brand" />
        <x-ui.stat label="Ejecuciones (30 días)" :value="number_format($kpi['runs'])" icon="play" color="navy" />
        <x-ui.stat label="Exitosas" :value="number_format($kpi['success'])" icon="check-circle" color="success" :sub="number_format($kpi['skipped']).' omitidas por condiciones'" />
        <x-ui.stat label="Con errores" :value="number_format($kpi['failed'])" icon="exclamation-triangle" color="danger" sub="Revisa el historial" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="['reglas' => 'Reglas', 'historial' => 'Historial de ejecuciones']" />

    @if ($tab === 'reglas')
        {{-- Plantillas de la sección 46 --}}
        <div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($templates as $key => $t)
                <div class="rounded-xl border border-dashed border-brand-200 bg-brand-50/50 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-2xs font-semibold tracking-wide text-brand-600 uppercase">Plantilla</div>
                            <div class="text-sm font-bold text-navy-900">{{ $t['name'] }}</div>
                        </div>
                        <x-ui.button size="sm" icon="plus" wire:click="createFromTemplate('{{ $key }}')">Crear en un clic</x-ui.button>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-2xs">
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-600 px-2 py-0.5 font-semibold text-white"><x-dynamic-component :component="'heroicon-o-'.RC::eventIcon($t['event'])" class="h-3 w-3" />{{ config('wisp.automation_events.'.$t['event']) }}</span>
                        @foreach ($t['conditions'] as $c)
                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-slate-400" />
                            <span class="rounded-full bg-warning-100 px-2 py-0.5 font-medium text-warning-800">{{ RC::describeCondition($t['event'], $c) }}</span>
                        @endforeach
                        @foreach ($t['actions'] as $a)
                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-slate-400" />
                            <span class="rounded-full bg-success-100 px-2 py-0.5 font-medium text-success-800">{{ config('wisp.automation_actions.'.$a['type']) }}</span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('filterEvent', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterEvent === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterEvent !== ''])>Todos los eventos</button>
            @foreach (config('wisp.automation_events') as $k => $l)
                <button wire:click="$set('filterEvent', '{{ $k }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterEvent === $k, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterEvent !== $k])>{{ $l }}</button>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            @forelse ($rules as $rule)
                <div wire:key="r{{ $rule->id }}" @class(['card flex flex-col', 'opacity-70' => ! $rule->active])>
                    <div class="flex items-start gap-3 border-b border-slate-100 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('automations.edit', $rule) }}" wire:navigate class="truncate text-[15px] font-bold text-navy-900 hover:text-brand-600">{{ $rule->name }}</a>
                                @if ($rule->system)<x-ui.badge color="navy" size="xs">Sistema</x-ui.badge>@endif
                            </div>
                            @if ($rule->description)<p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $rule->description }}</p>@endif
                        </div>
                        <button type="button" wire:click="toggle({{ $rule->id }})" title="{{ $rule->active ? 'Desactivar' : 'Activar' }}"
                                @class(['relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full transition', 'bg-brand-600' => $rule->active, 'bg-slate-300' => ! $rule->active])>
                            <span @class(['absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition', 'translate-x-4' => $rule->active])></span>
                        </button>
                    </div>

                    {{-- Cadena visual --}}
                    <div class="flex-1 px-5 py-4">
                        <div class="flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <span class="w-20 shrink-0 text-2xs font-bold tracking-wide text-slate-400 uppercase">Evento</span>
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-semibold text-white">
                                    <x-dynamic-component :component="'heroicon-o-'.RC::eventIcon($rule->event)" class="h-3.5 w-3.5" />
                                    {{ config('wisp.automation_events.'.$rule->event, $rule->event) }}
                                </span>
                            </div>
                            <div class="flex items-start gap-2">
                                <span class="w-20 shrink-0 pt-1 text-2xs font-bold tracking-wide text-slate-400 uppercase">Si</span>
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($rule->conditions ?? [] as $c)
                                        <span class="rounded-lg bg-warning-50 px-2 py-1 text-xs font-medium text-warning-800 ring-1 ring-warning-100">{{ RC::describeCondition($rule->event, $c) }}</span>
                                    @empty
                                        <span class="rounded-lg bg-slate-100 px-2 py-1 text-xs text-slate-500">Siempre</span>
                                    @endforelse
                                </div>
                            </div>
                            <div class="flex items-start gap-2">
                                <span class="w-20 shrink-0 pt-1 text-2xs font-bold tracking-wide text-slate-400 uppercase">Entonces</span>
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach ($rule->actions ?? [] as $a)
                                        @if (! $loop->first)<x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-slate-300" />@endif
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-success-50 px-2 py-1 text-xs font-medium text-success-800 ring-1 ring-success-100">
                                            <x-dynamic-component :component="'heroicon-o-'.(RC::ACTION_ICONS[$a['type'] ?? ''] ?? 'bolt')" class="h-3.5 w-3.5" />
                                            {{ RC::describeAction($a) }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-b-xl border-t border-slate-100 bg-slate-50/60 px-5 py-2.5">
                        <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-500">
                            <span><b class="text-slate-700">{{ number_format($rule->run_count) }}</b> ejecuciones</span>
                            <span>{{ number_format($runsByRule[$rule->id] ?? 0) }} en 30 días</span>
                            <span>Última: {{ $rule->last_run_at ? $rule->last_run_at->diffForHumans() : 'nunca' }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <x-ui.button size="sm" variant="ghost" icon="clock" wire:click="showRuns({{ $rule->id }})" title="Historial" />
                            <x-ui.button size="sm" variant="secondary" icon="pencil" :href="route('automations.edit', $rule)">Editar</x-ui.button>
                            <x-ui.dropdown>
                                <x-slot:trigger><x-ui.button size="sm" variant="ghost" icon="ellipsis-vertical" /></x-slot:trigger>
                                <x-ui.dropdown-item icon="beaker" :href="route('automations.edit', $rule)">Probar regla</x-ui.dropdown-item>
                                <x-ui.dropdown-item icon="document-duplicate" wire:click="duplicate({{ $rule->id }})">Duplicar</x-ui.dropdown-item>
                                @unless ($rule->system)
                                    <x-ui.dropdown-item icon="trash" danger wire:click="delete({{ $rule->id }})" wire:confirm="¿Eliminar la regla {{ $rule->name }}?">Eliminar</x-ui.dropdown-item>
                                @endunless
                            </x-ui.dropdown>
                        </div>
                    </div>
                </div>
            @empty
                <div class="card xl:col-span-2">
                    <x-ui.empty icon="bolt" title="No hay reglas para este evento" description="Crea una regla o usa una plantilla.">
                        <x-ui.button icon="plus" :href="route('automations.create')">Nueva regla</x-ui.button>
                    </x-ui.empty>
                </div>
            @endforelse
        </div>
    @else
        <x-ui.card :padding="false">
            <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
                <x-ui.select wire:model.live="filterRule" :options="$ruleOptions" placeholder="Todas las reglas" class="md:col-span-2" />
                <x-ui.select wire:model.live="filterRunStatus" :options="['success' => 'Ejecutadas', 'skipped' => 'Omitidas (condiciones)', 'failed' => 'Con errores']" placeholder="Todos los resultados" />
            </div>
            <x-ui.table>
                <x-slot:head><th>Fecha</th><th>Regla</th><th>Sujeto</th><th>Resultado</th><th>Bitácora</th></x-slot:head>
                @forelse ($runs as $run)
                    @php
                        [$rl, $rc] = ['success' => ['Ejecutada', 'success'], 'skipped' => ['Omitida', 'slate'], 'failed' => ['Error', 'danger']][$run->status] ?? [$run->status, 'slate'];
                        $u = RC::subjectUrl($run->subject_type, $run->subject_id);
                        $label = $labels[$run->subject_type.':'.$run->subject_id] ?? ('#'.$run->subject_id);
                    @endphp
                    <tr wire:key="run{{ $run->id }}">
                        <td class="whitespace-nowrap text-xs text-slate-600"><div class="font-medium text-slate-700">{{ $run->created_at->format('d/m/Y') }}</div>{{ $run->created_at->format('H:i:s') }}</td>
                        <td>
                            @if ($run->rule)
                                <a href="{{ route('automations.edit', $run->rule) }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-600">{{ $run->rule->name }}</a>
                                <div class="text-xs text-slate-500">{{ config('wisp.automation_events.'.$run->rule->event) }}</div>
                            @endif
                        </td>
                        <td class="text-xs">
                            <div class="text-slate-500">{{ RC::SUBJECTS[$run->subject_type] ?? $run->subject_type }}</div>
                            @if ($u)<a href="{{ $u }}" wire:navigate class="link">{{ $label }}</a>@else<span class="text-slate-700">{{ $label }}</span>@endif
                        </td>
                        <td><x-ui.badge :color="$rc" dot>{{ $rl }}</x-ui.badge></td>
                        <td class="text-xs text-slate-600">
                            @foreach ($run->log ?? [] as $line)<div @class(['text-danger-600' => str_starts_with($line, 'Error')])>• {{ $line }}</div>@endforeach
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" icon="clock" title="Sin ejecuciones" />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $runs->links() }}</div>
        </x-ui.card>
    @endif
</div>
