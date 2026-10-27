@php use App\Models\DemoRequest; @endphp
<div>
    <x-ui.page-header title="Prospectos" subtitle="Embudo de venta de ID-Hub: de la solicitud de demo al WISP que paga">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-xs">
            @foreach (\App\Livewire\Saas\Sales\Leads::VIEWS as $key => [$label, $icon])
                <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', 'bg-navy-900 text-white' => $view === $key, 'text-slate-600 hover:bg-slate-50' => $view !== $key])>
                    <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" /> {{ $label }}
                </button>
            @endforeach
        </div>
        <x-ui.button variant="secondary" icon="calendar-days" :href="route('saas.sales.agenda')">Agenda</x-ui.button>
        <x-ui.button icon="plus" wire:click="create">Nuevo prospecto</x-ui.button>
        <x-ui.dropdown width="w-72">
            <x-slot:trigger><x-ui.button variant="secondary" icon="ellipsis-vertical" title="Más opciones" /></x-slot:trigger>
            <x-ui.dropdown-item icon="beaker" wire:click="generateSamples" wire:confirm="Se cargarán 45 prospectos de ejemplo (correo @prospecto-demo.mx) con historial y demos. No se envía ningún correo. ¿Continuar?">
                {{ $samples ? 'Regenerar prospectos de ejemplo' : 'Cargar prospectos de ejemplo' }}
            </x-ui.dropdown-item>
            @if ($samples)
                <x-ui.dropdown-item icon="trash" danger wire:click="clearSamples" wire:confirm="¿Eliminar los {{ $samples }} prospectos de ejemplo? Los prospectos reales no se tocan.">Eliminar prospectos de ejemplo ({{ $samples }})</x-ui.dropdown-item>
            @endif
        </x-ui.dropdown>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat variant="soft" label="Prospectos abiertos" :value="number_format($summary['open'])" icon="user-group" color="navy" />
        <x-ui.stat variant="soft" label="Nuevos sin atender" :value="number_format($summary['new'])" icon="sparkles" color="brand" />
        <x-ui.stat variant="soft" label="Seguimientos vencidos" :value="number_format($summary['overdue'])" icon="clock" :color="$summary['overdue'] ? 'danger' : 'success'" />
        <x-ui.stat variant="soft" label="Demos de hoy" :value="number_format($summary['today'])" icon="calendar-days" color="purple" :href="route('saas.sales.agenda', ['vista' => 'lista'])" />
    </div>

    @if ($view !== 'metricas')
        <x-ui.card :padding="false">
            <div class="filter-bar">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Nombre, WISP, correo, teléfono o ciudad..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterAssignee" :options="['none' => 'Sin asignar'] + $users" placeholder="Todos los responsables" />
                <x-ui.select wire:model.live="filterSource" :options="DemoRequest::SOURCES" placeholder="Todos los orígenes" />
                @if ($view === 'lista')
                    <x-ui.select wire:model.live="filterStage" :options="DemoRequest::STAGES" placeholder="Todas las etapas" />
                @endif
                <label @class(["flex items-center gap-2 text-sm whitespace-nowrap text-slate-600", "md:col-span-2" => $view === "tablero"])>
                    <input type="checkbox" wire:model.live="filterOverdue" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"> Solo seguimientos vencidos
                </label>
            </div>

            {{-- ============================== TABLERO ============================== --}}
            @if ($view === 'tablero')
                <p class="px-4 pt-3 text-xs text-slate-500">Arrastra las tarjetas entre columnas para cambiar la etapa. Ganados y perdidos muestran los últimos 60 días.</p>
                <div class="flex gap-3 overflow-x-auto p-4">
                    @foreach ($columns as $stage => $col)
                        <div wire:key="col-{{ $stage }}" x-data="{ over: false }"
                             x-on:dragover.prevent="over = true" x-on:dragleave.self="over = false"
                             x-on:drop.prevent="over = false; $wire.move(parseInt($event.dataTransfer.getData('text/plain')), '{{ $stage }}')"
                             :class="over && 'ring-2 ring-brand-400 bg-brand-50/60'"
                             class="flex w-72 shrink-0 flex-col rounded-xl bg-slate-50 ring-1 ring-slate-200/70 transition">
                            <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                                <x-ui.badge :color="DemoRequest::STAGE_COLORS[$stage]" dot>{{ $col['label'] }}</x-ui.badge>
                                <span class="text-xs font-semibold text-slate-500">{{ $col['total'] }}@if ($col['mrr'] > 0) · {{ money($col['mrr'], false) }}@endif</span>
                            </div>
                            <div class="min-h-24 max-h-[620px] space-y-2 overflow-y-auto px-2 pb-2">
                                @forelse ($col['items'] as $l)
                                    @php $plan = $l->tenant?->saasPlan ?? $l->planOrSuggested(); @endphp
                                    <div wire:key="card-{{ $l->id }}" draggable="true"
                                         x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $l->id }}'); $event.dataTransfer.effectAllowed = 'move'"
                                         class="cursor-grab rounded-lg border border-slate-200 bg-white p-3 shadow-xs active:cursor-grabbing">
                                        <div class="flex items-start justify-between gap-2">
                                            <a href="{{ route('saas.sales.leads.show', $l) }}" wire:navigate class="min-w-0">
                                                <div class="truncate text-sm font-semibold text-slate-800 hover:text-brand-600">{{ $l->company ?: $l->name }}</div>
                                                <div class="truncate text-xs text-slate-500">{{ $l->company ? $l->name : ($l->email ?: $l->phone) }}{{ $l->location ? ' · '.$l->location : '' }}</div>
                                            </a>
                                            <x-ui.dropdown width="w-52">
                                                <x-slot:trigger><x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" /></x-slot:trigger>
                                                <x-ui.dropdown-item icon="eye" :href="route('saas.sales.leads.show', $l)">Abrir ficha</x-ui.dropdown-item>
                                                @if ($l->isOpen())
                                                    <x-ui.dropdown-item icon="calendar-days" x-on:click="$dispatch('schedule-demo', { leadId: {{ $l->id }} })">Agendar demo</x-ui.dropdown-item>
                                                @endif
                                                <div class="my-1 border-t border-slate-100"></div>
                                                <div class="px-3 pt-1 pb-0.5 text-2xs font-semibold tracking-wide text-slate-400 uppercase">Mover a</div>
                                                @foreach (DemoRequest::STAGES as $st => $stLabel)
                                                    @continue($st === $l->status)
                                                    <x-ui.dropdown-item wire:click="move({{ $l->id }}, '{{ $st }}')" :danger="$st === 'lost'">{{ $stLabel }}</x-ui.dropdown-item>
                                                @endforeach
                                            </x-ui.dropdown>
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                            @if ($l->clients)<x-ui.badge color="slate" size="xs">{{ $l->clients }}</x-ui.badge>@endif
                                            @if ($plan)<x-ui.badge color="brand" size="xs">{{ $plan->name }} · {{ money($l->mrr(), false) }}</x-ui.badge>@endif
                                            @if ($l->status === 'lost' && $l->lost_reason)<x-ui.badge color="danger" size="xs">{{ DemoRequest::LOST_REASONS[$l->lost_reason] ?? $l->lost_reason }}</x-ui.badge>@endif
                                        </div>
                                        @if ($l->next_meeting_at)
                                            <div class="mt-2 flex items-center gap-1 text-2xs font-semibold text-purple-700">
                                                <x-heroicon-m-calendar-days class="h-3.5 w-3.5" /> Demo {{ \Carbon\Carbon::parse($l->next_meeting_at)->translatedFormat('D j M, H:i') }}
                                            </div>
                                        @endif
                                        @if ($l->isOpen() && $l->next_action)
                                            <div @class(['mt-1.5 flex items-start gap-1 text-2xs', 'font-semibold text-danger-600' => $l->isOverdue(), 'text-slate-500' => ! $l->isOverdue()])>
                                                <x-heroicon-m-arrow-right-circle class="mt-px h-3.5 w-3.5 shrink-0" />
                                                <span class="min-w-0">{{ $l->next_action }}@if ($l->next_action_at) · {{ $l->next_action_at->translatedFormat('j M H:i') }}@endif</span>
                                            </div>
                                        @endif
                                        <div class="mt-2 flex items-center justify-between text-2xs text-slate-400">
                                            <span>{{ DemoRequest::SOURCES[$l->source] ?? $l->source }} · {{ $l->created_at->diffForHumans(short: true) }}</span>
                                            @if ($l->assignee)<x-ui.avatar :name="$l->assignee->name" size="sm" :title="$l->assignee->name" />@endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-2 py-6 text-center text-xs text-slate-400">Sin prospectos</p>
                                @endforelse
                                @if ($col['total'] > $col['items']->count())
                                    <button type="button" wire:click="showStage('{{ $stage }}')" class="w-full py-1 text-center text-xs font-semibold text-brand-600">Ver los {{ $col['total'] }} →</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- ============================== LISTA ============================== --}}
            @if ($view === 'lista')
                <x-ui.table>
                    <x-slot:head>
                        <th>Prospecto</th>
                        <th>Etapa</th>
                        <th>Plan · MRR</th>
                        <th>Siguiente paso</th>
                        <th>Responsable</th>
                        <th>Recibido</th>
                    </x-slot:head>
                    @forelse ($leads as $l)
                        @php $plan = $l->tenant?->saasPlan ?? $l->planOrSuggested(); @endphp
                        <tr wire:key="l{{ $l->id }}">
                            <td>
                                <a href="{{ route('saas.sales.leads.show', $l) }}" wire:navigate class="flex items-center gap-3">
                                    <x-ui.avatar :name="$l->company ?: $l->name" />
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-800 hover:text-brand-600">{{ $l->company ?: $l->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $l->company ? $l->name.' · ' : '' }}{{ $l->email ?: $l->phone }}</div>
                                    </div>
                                </a>
                            </td>
                            <td><x-ui.badge :color="DemoRequest::STAGE_COLORS[$l->status] ?? 'slate'" dot>{{ $l->stageLabel() }}</x-ui.badge></td>
                            <td class="text-sm text-slate-600 whitespace-nowrap">{{ $plan?->name ?? '—' }}@if ($l->mrr() > 0)<div class="text-xs text-slate-400">{{ money($l->mrr(), false) }}/mes</div>@endif</td>
                            <td @class(['text-sm', 'font-semibold text-danger-600' => $l->isOverdue(), 'text-slate-600' => ! $l->isOverdue()])>
                                @if ($l->next_meeting_at)<div class="text-xs font-semibold text-purple-700">Demo {{ \Carbon\Carbon::parse($l->next_meeting_at)->translatedFormat('D j M, H:i') }}</div>@endif
                                {{ $l->isOpen() ? ($l->next_action ?: '—') : '—' }}
                                @if ($l->isOpen() && $l->next_action_at)<div class="text-xs">{{ $l->next_action_at->translatedFormat('j M Y H:i') }}</div>@endif
                            </td>
                            <td class="text-sm text-slate-600">{{ $l->assignee?->name ?? 'Sin asignar' }}</td>
                            <td class="text-sm whitespace-nowrap text-slate-600">{{ $l->created_at->format('d/m/Y') }}<div class="text-xs text-slate-400">{{ DemoRequest::SOURCES[$l->source] ?? $l->source }}</div></td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="user-group" title="Sin prospectos" description="Las solicitudes de la página pública llegan aquí automáticamente; también puedes capturarlos a mano." />
                    @endforelse
                </x-ui.table>
                @if ($leads->hasPages())
                    <div class="border-t border-slate-100 px-4 py-3">{{ $leads->links() }}</div>
                @endif
            @endif
        </x-ui.card>
    @endif

    {{-- ============================== MÉTRICAS ============================== --}}
    @if ($view === 'metricas')
        <div class="mb-4 flex justify-end">
            <x-ui.select wire:model.live="period" :options="['30' => 'Últimos 30 días', '90' => 'Últimos 90 días', '180' => 'Últimos 6 meses', '365' => 'Último año']" class="w-48" />
        </div>
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <x-ui.stat label="Prospectos nuevos" :value="number_format($m['kpi']['leads'])" icon="user-plus" color="navy" />
            <x-ui.stat label="Demos realizadas" :value="number_format($m['kpi']['demos'])" icon="presentation-chart-bar" color="purple" />
            <x-ui.stat label="Tasa de cierre" :value="$m['kpi']['conversion'] === null ? '—' : $m['kpi']['conversion'].'%'" icon="arrow-trending-up" color="brand" :sub="$m['kpi']['won'].' ganados'" />
            <x-ui.stat label="MRR ganado" :value="money($m['kpi']['mrr'], false)" icon="banknotes" color="success" />
            <x-ui.stat label="Primera respuesta" :value="$m['kpi']['response'] === null ? '—' : ($m['kpi']['response'] < 60 ? $m['kpi']['response'].' min' : round($m['kpi']['response'] / 60, 1).' h')" icon="bolt" color="teal" sub="Promedio" />
        </div>
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <x-ui.card title="Embudo" subtitle="Prospectos del periodo que llegaron a cada etapa">
                <div class="space-y-3">
                    @foreach ($m['funnel'] as $stage => $f)
                        <div>
                            <div class="mb-1 flex justify-between text-sm"><span class="font-medium text-slate-700">{{ $f['label'] }}</span><span class="text-slate-500">{{ number_format($f['n']) }} · {{ $f['pct'] }}%</span></div>
                            <x-ui.progress :value="$f['pct']" :color="DemoRequest::STAGE_COLORS[$stage] === 'navy' ? 'slate' : DemoRequest::STAGE_COLORS[$stage]" />
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
            <x-ui.card title="Prospectos y demos por semana">
                <x-ui.chart id="sales-weekly" :options="$m['chart']" height="280px" />
            </x-ui.card>
            <x-ui.card title="Motivos de pérdida" :subtitle="$m['lostTotal'].' perdidos en el periodo'">
                <div class="space-y-3">
                    @forelse ($m['lost'] as $reason => $n)
                        <div>
                            <div class="mb-1 flex justify-between text-sm"><span class="text-slate-700">{{ DemoRequest::LOST_REASONS[$reason] ?? $reason }}</span><span class="text-slate-500">{{ $n }}</span></div>
                            <x-ui.progress :value="$m['lostTotal'] ? $n / $m['lostTotal'] * 100 : 0" color="danger" />
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-400">Sin prospectos perdidos en el periodo.</p>
                    @endforelse
                </div>
            </x-ui.card>
            <x-ui.card title="Origen de los prospectos" :padding="false">
                <x-ui.table>
                    <x-slot:head><th>Origen</th><th class="text-right">Prospectos</th><th class="text-right">Ganados</th><th class="text-right">Conversión</th></x-slot:head>
                    @forelse ($m['sources'] as $source => $s)
                        <tr>
                            <td class="text-sm font-medium text-slate-700">{{ DemoRequest::SOURCES[$source] ?? $source }}</td>
                            <td class="text-right text-sm">{{ $s['n'] }}</td>
                            <td class="text-right text-sm">{{ $s['won'] }}</td>
                            <td class="text-right text-sm">{{ $s['n'] ? round($s['won'] / $s['n'] * 100) : 0 }}%</td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="4" icon="chart-bar" title="Sin datos en el periodo" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    @endif

    {{-- Captura manual --}}
    <x-ui.modal wire:model="showForm" title="Nuevo prospecto" size="2xl">
        <form wire:submit="save" id="lead-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Nombre del contacto" wire:model="form.name" maxlength="120" required />
            <x-ui.input label="Nombre del WISP" wire:model="form.company" maxlength="160" />
            <x-ui.input label="Teléfono / WhatsApp" type="tel" wire:model="form.phone" filter="phone" />
            <x-ui.input label="Correo" type="email" wire:model="form.email" filter="email" maxlength="160" />
            <x-ui.input label="Ciudad / estado" wire:model="form.location" maxlength="120" placeholder="Querétaro, Qro." />
            <x-ui.select label="Clientes actuales" wire:model="form.clients" :options="array_combine(array_keys(DemoRequest::CLIENT_RANGES), array_keys(DemoRequest::CLIENT_RANGES))" placeholder="Sin dato" />
            <x-ui.select label="Sistema que usa hoy" wire:model="form.current_software" :options="array_combine(DemoRequest::SOFTWARE, DemoRequest::SOFTWARE)" placeholder="Sin dato" />
            <x-ui.select label="Origen" wire:model="form.source" :options="DemoRequest::SOURCES" required />
            <x-ui.select label="Responsable" wire:model="form.assigned_to" :options="$users" placeholder="Sin asignar" />
            <div class="hidden sm:block"></div>
            <x-ui.input label="Siguiente paso" wire:model="form.next_action" maxlength="190" placeholder="Llamar para agendar demo" />
            <x-ui.input label="¿Cuándo?" type="datetime-local" wire:model="form.next_action_at" />
            <x-ui.textarea label="Nota" wire:model="form.message" rows="2" maxlength="1500" class="sm:col-span-2" placeholder="Qué necesita, cómo llegó..." />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="lead-form" icon="check">Guardar prospecto</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @include('livewire.saas.sales.partials.lost-modal')

    <livewire:saas.sales.meeting-form />
</div>
