<div>
    <x-ui.page-header title="Políticas SLA" subtitle="Tiempo objetivo de resolución por prioridad y alertas automáticas de vencimiento"
                      :breadcrumbs="['Tickets' => route('tickets.index'), 'Políticas SLA' => null]">
        <x-ui.button variant="secondary" icon="bolt" wire:click="runCheck">Revisar SLA ahora</x-ui.button>
        <x-ui.button icon="check" wire:click="save">Guardar políticas</x-ui.button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card title="Tiempo objetivo por prioridad" :padding="false">
                <x-ui.table>
                    <x-slot:head>
                        <th>Prioridad</th><th class="w-40">Tiempo objetivo</th><th class="w-36">Aviso al</th><th>Abiertos</th><th>Vencidos</th><th>Cumplimiento 30 días</th><th>Resolución prom.</th>
                    </x-slot:head>
                    @foreach (config('wisp.ticket_priorities') as $key => [$label, $color])
                        @php $s = $stats[$key]; @endphp
                        <tr wire:key="p{{ $key }}">
                            <td><x-ui.badge :color="$color" dot>{{ $label }}</x-ui.badge></td>
                            <td><x-ui.input type="number" step="0.25" min="0.25" wire:model="policies.{{ $key }}.hours" suffix="h" /></td>
                            <td><x-ui.input type="number" step="5" min="10" max="99" wire:model="policies.{{ $key }}.warn" suffix="%" /></td>
                            <td class="font-semibold text-slate-700">{{ $s['open'] }}</td>
                            <td class="font-semibold {{ $s['overdue'] ? 'text-danger-600' : 'text-slate-400' }}">{{ $s['overdue'] }}</td>
                            <td class="min-w-36">
                                @if ($s['pct'] !== null)
                                    <x-ui.progress :value="$s['pct']" :color="$s['pct'] >= 90 ? 'success' : ($s['pct'] >= 75 ? 'warning' : 'danger')" label />
                                    <div class="text-[11px] text-slate-400">{{ $s['resolved'] }} resueltos</div>
                                @else
                                    <span class="text-xs text-slate-400">Sin datos</span>
                                @endif
                            </td>
                            <td class="text-sm text-slate-700">{{ \App\Livewire\Tickets\Index::duration($s['avg']) }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
                <div class="flex flex-col gap-3 border-t border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500">Los cambios aplican a tickets nuevos. Puedes recalcular el vencimiento de los tickets abiertos con las políticas guardadas.</p>
                    <x-ui.button size="sm" variant="secondary" icon="arrow-path" wire:click="recalculate" wire:confirm="¿Recalcular el vencimiento de todos los tickets abiertos con las políticas actuales?">Recalcular abiertos</x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card title="¿Cómo funcionan las alertas?">
                <ol class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                    <li class="rounded-xl bg-brand-50/70 p-4">
                        <x-heroicon-o-clock class="mb-2 h-6 w-6 text-brand-600" />
                        <div class="font-semibold text-navy-900">1. Creación</div>
                        <p class="mt-1 text-xs text-slate-600">Al crear el ticket se fija su vencimiento: fecha de alta + tiempo objetivo de su prioridad.</p>
                    </li>
                    <li class="rounded-xl bg-warning-50 p-4">
                        <x-heroicon-o-bell-alert class="mb-2 h-6 w-6 text-warning-600" />
                        <div class="font-semibold text-navy-900">2. Próximo a vencer</div>
                        <p class="mt-1 text-xs text-slate-600">Cada 5 minutos se revisan los tickets abiertos; al consumir el % de aviso se genera la alerta «Ticket próximo a vencer» y se dispara la automatización <b>ticket_sla_risk</b>.</p>
                    </li>
                    <li class="rounded-xl bg-danger-50 p-4">
                        <x-heroicon-o-exclamation-triangle class="mb-2 h-6 w-6 text-danger-600" />
                        <div class="font-semibold text-navy-900">3. Vencido</div>
                        <p class="mt-1 text-xs text-slate-600">Si se supera el tiempo objetivo se marca como fuera de SLA y se genera una alerta crítica. Al cerrar el ticket sus alertas se resuelven solas.</p>
                    </li>
                </ol>
            </x-ui.card>
        </div>

        <x-ui.card title="Alertas de SLA abiertas" :padding="false">
            <div class="divide-y divide-slate-100">
                @forelse ($alerts as $a)
                    <a @if ($a->subjectUrl()) href="{{ $a->subjectUrl() }}" wire:navigate @endif class="flex items-start gap-3 px-5 py-3 hover:bg-slate-50">
                        <span @class(['mt-1 h-2.5 w-2.5 shrink-0 rounded-full', 'bg-danger-600' => $a->type === 'sla_breached', 'bg-warning-500' => $a->type !== 'sla_breached'])></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-800">{{ $a->title }}</span>
                            <span class="block text-xs text-slate-500">{{ $a->message }}</span>
                            <span class="block text-[11px] text-slate-400">{{ $a->created_at->diffForHumans() }}</span>
                        </span>
                    </a>
                @empty
                    <x-ui.empty icon="check-badge" title="Sin alertas de SLA" description="Todos los tickets abiertos están dentro de tiempo." />
                @endforelse
            </div>
        </x-ui.card>
    </div>
</div>
