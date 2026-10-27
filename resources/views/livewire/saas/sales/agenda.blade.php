@php use App\Models\DemoMeeting; @endphp
<div>
    <x-ui.page-header title="Agenda de demos" subtitle="Agendadas por tu equipo o elegidas por el prospecto en la página web">
        <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 shadow-xs">
            @foreach (\App\Livewire\Saas\Sales\Agenda::VIEWS as $key => [$label, $icon])
                <button type="button" wire:click="$set('view', '{{ $key }}')" @class(['inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition', 'bg-navy-900 text-white' => $view === $key, 'text-slate-600 hover:bg-slate-50' => $view !== $key])>
                    <x-dynamic-component :component="'heroicon-m-'.$icon" class="h-4 w-4" /> {{ $label }}
                </button>
            @endforeach
        </div>
        <x-ui.button variant="secondary" icon="user-group" :href="route('saas.sales.leads')">Prospectos</x-ui.button>
        <x-ui.button icon="plus" x-on:click="$dispatch('schedule-demo', {})">Agendar demo</x-ui.button>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
            @if ($view !== 'lista')
                <div class="flex items-center gap-1">
                    <x-ui.button variant="ghost" size="sm" icon="chevron-left" wire:click="shift(-1)" title="Anterior" />
                    <x-ui.button variant="secondary" size="sm" wire:click="goToday">Hoy</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" icon="chevron-right" wire:click="shift(1)" title="Siguiente" />
                </div>
            @endif
            <h2 class="text-base font-bold text-navy-900">{{ $heading }}</h2>
            <span wire:loading class="text-xs text-brand-600">Cargando…</span>
            <x-ui.select wire:model.live="filterAssignee" :options="$users" placeholder="Todos los responsables" class="ml-auto w-56" />
        </div>

        {{-- ============================== MES ============================== --}}
        @if ($view === 'mes')
            <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-2xs font-semibold tracking-wide text-slate-500 uppercase">
                @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $wd)<div class="py-2">{{ $wd }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @foreach ($days as $d)
                    @php $items = $byDay[$d->toDateString()] ?? collect(); @endphp
                    <div wire:key="d{{ $d->toDateString() }}" @class(['group min-h-28 border-r border-b border-slate-100 p-1.5', 'bg-slate-50/70' => $d->month !== $anchor->month])>
                        <div class="mb-1 flex items-center justify-between">
                            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold', 'bg-brand-600 text-white' => $d->isToday(), 'text-slate-700' => ! $d->isToday() && $d->month === $anchor->month, 'text-slate-400' => $d->month !== $anchor->month])>{{ $d->day }}</span>
                            @if (! $d->isPast() || $d->isToday())
                                <button type="button" x-on:click="$dispatch('schedule-demo', { date: '{{ $d->toDateString() }}' })" class="hidden h-5 w-5 items-center justify-center rounded text-slate-400 group-hover:flex hover:bg-brand-50 hover:text-brand-600" title="Agendar este día"><x-heroicon-m-plus class="h-4 w-4" /></button>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @foreach ($items->take(4) as $m)
                                <button type="button" wire:key="mm{{ $m->id }}" wire:click="select({{ $m->id }})" @class([
                                    'block w-full truncate rounded px-1.5 py-0.5 text-left text-2xs font-medium',
                                    'bg-purple-100 text-purple-800 hover:bg-purple-200' => $m->status === 'scheduled',
                                    'bg-success-50 text-success-700' => $m->status === 'done',
                                    'bg-warning-50 text-warning-700' => $m->status === 'no_show',
                                    'bg-slate-100 text-slate-400 line-through' => $m->status === 'cancelled',
                                ])>{{ $m->starts_at->format('H:i') }} {{ $m->lead->company ?: $m->lead->name }}</button>
                            @endforeach
                            @if ($items->count() > 4)
                                <button type="button" wire:click="showWeek('{{ $d->toDateString() }}')" class="px-1.5 text-2xs font-semibold text-brand-600">+{{ $items->count() - 4 }} más</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================== SEMANA ============================== --}}
        @if ($view === 'semana')
            <div class="grid grid-cols-1 divide-y divide-slate-100 md:grid-cols-7 md:divide-x md:divide-y-0">
                @foreach ($days as $d)
                    @php $items = $byDay[$d->toDateString()] ?? collect(); @endphp
                    <div wire:key="w{{ $d->toDateString() }}" @class(['min-h-64 p-2', 'bg-brand-50/40' => $d->isToday()])>
                        <div class="mb-2 flex items-center justify-between">
                            <div>
                                <div class="text-2xs font-semibold text-slate-500 uppercase">{{ $d->translatedFormat('D') }}</div>
                                <div @class(['text-lg font-bold', 'text-brand-600' => $d->isToday(), 'text-navy-900' => ! $d->isToday()])>{{ $d->day }}</div>
                            </div>
                            @if (! $d->isPast() || $d->isToday())
                                <button type="button" x-on:click="$dispatch('schedule-demo', { date: '{{ $d->toDateString() }}' })" class="rounded p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Agendar este día"><x-heroicon-m-plus class="h-4 w-4" /></button>
                            @endif
                        </div>
                        <div class="space-y-2">
                            @forelse ($items as $m)
                                <button type="button" wire:key="wm{{ $m->id }}" wire:click="select({{ $m->id }})" @class([
                                    'block w-full rounded-lg border-l-4 px-2 py-1.5 text-left',
                                    'border-purple-500 bg-purple-50 hover:bg-purple-100' => $m->status === 'scheduled',
                                    'border-success-500 bg-success-50' => $m->status === 'done',
                                    'border-warning-500 bg-warning-50' => $m->status === 'no_show',
                                    'border-slate-300 bg-slate-50 opacity-60' => $m->status === 'cancelled',
                                ])>
                                    <div class="text-xs font-bold text-slate-800">{{ $m->starts_at->format('H:i') }}–{{ $m->endsAt()->format('H:i') }}</div>
                                    <div class="truncate text-xs text-slate-700">{{ $m->lead->company ?: $m->lead->name }}</div>
                                    @if ($m->assignee)<div class="truncate text-2xs text-slate-400">{{ $m->assignee->name }}</div>@endif
                                </button>
                            @empty
                                <p class="py-4 text-center text-2xs text-slate-300">—</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ============================== LISTA ============================== --}}
        @if ($view === 'lista')
            <ul class="divide-y divide-slate-100">
                @forelse ($meetings as $m)
                    <li wire:key="lm{{ $m->id }}" @class(['flex flex-wrap items-center gap-4 px-5 py-3', 'opacity-60' => $m->starts_at->isPast() && $m->status !== 'scheduled'])>
                        <div class="w-28 shrink-0">
                            <div @class(['text-sm font-bold', 'text-brand-600' => $m->starts_at->isToday(), 'text-navy-900' => ! $m->starts_at->isToday()])>{{ $m->starts_at->isToday() ? 'Hoy' : ($m->starts_at->isTomorrow() ? 'Mañana' : \Illuminate\Support\Str::ucfirst($m->starts_at->translatedFormat('D j M'))) }}</div>
                            <div class="text-xs text-slate-500">{{ $m->starts_at->format('H:i') }} · {{ $m->duration_minutes }} min</div>
                        </div>
                        <a href="{{ route('saas.sales.leads.show', $m->demo_request_id) }}" wire:navigate class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-slate-800 hover:text-brand-600">{{ $m->lead->company ?: $m->lead->name }}</div>
                            <div class="truncate text-xs text-slate-500">{{ $m->lead->name }}{{ $m->assignee ? ' · con '.$m->assignee->name : '' }}{{ $m->booked_by_prospect ? ' · la eligió el prospecto' : '' }}</div>
                        </a>
                        <x-ui.badge :color="DemoMeeting::STATUS_COLORS[$m->status] ?? 'slate'">{{ $m->statusLabel() }}</x-ui.badge>
                        <x-ui.button variant="secondary" size="sm" icon="eye" wire:click="select({{ $m->id }})">Ver</x-ui.button>
                    </li>
                @empty
                    <li><x-ui.empty icon="calendar-days" title="Sin demos próximas" description="Agenda una desde aquí o desde la ficha de un prospecto." /></li>
                @endforelse
            </ul>
        @endif
    </x-ui.card>

    <p class="mt-3 text-xs text-slate-500">
        @if ($selfBooking)
            Autoagenda activa en la página pública: {{ $bookingDays }} a las {{ $bookingHours }} h. Los horarios ocupados no se ofrecen.
        @else
            La autoagenda desde la página pública está desactivada (WISP_DEMO_SELF_BOOKING).
        @endif
    </p>

    {{-- Detalle de la demo --}}
    <x-ui.modal wire:model="showMeeting" title="Demo" size="lg">
        @if ($selected)
            @php $m = $selected; @endphp
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-lg font-bold text-navy-900">{{ $m->lead->company ?: $m->lead->name }}</div>
                    <div class="text-sm text-slate-500">{{ $m->lead->name }}{{ $m->lead->phone ? ' · '.$m->lead->phone : '' }}</div>
                </div>
                <x-ui.badge :color="DemoMeeting::STATUS_COLORS[$m->status] ?? 'slate'" dot>{{ $m->statusLabel() }}</x-ui.badge>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-4">
                <x-ui.detail label="Fecha">{{ \Illuminate\Support\Str::ucfirst($m->starts_at->translatedFormat('l j \d\e F Y')) }}</x-ui.detail>
                <x-ui.detail label="Hora">{{ $m->starts_at->format('H:i') }} – {{ $m->endsAt()->format('H:i') }}</x-ui.detail>
                <x-ui.detail label="Responsable">{{ $m->assignee?->name }}</x-ui.detail>
                <x-ui.detail label="Agendada por">{{ $m->booked_by_prospect ? 'El prospecto (página web)' : 'Equipo de ventas' }}</x-ui.detail>
                <x-ui.detail label="Videollamada" class="col-span-2">@if ($m->meeting_url)<a href="{{ $m->meeting_url }}" target="_blank" rel="noopener" class="break-all text-brand-600 hover:underline">{{ $m->meeting_url }}</a>@endif</x-ui.detail>
                @if ($m->notes)<x-ui.detail label="Notas" class="col-span-2">{{ $m->notes }}</x-ui.detail>@endif
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="ghost" icon="calendar" :href="$m->googleCalendarUrl()" :navigate="false" target="_blank" rel="noopener">Google Calendar</x-ui.button>
                <x-ui.button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('saas.sales.meeting.ics', $m)" :navigate="false">.ics</x-ui.button>
                <x-ui.button size="sm" variant="ghost" icon="user" :href="route('saas.sales.leads.show', $m->demo_request_id)">Ficha del prospecto</x-ui.button>
            </div>
        @endif
        <x-slot:footer>
            @if ($selected && $selected->status === 'scheduled')
                <x-ui.button variant="ghost" icon="x-circle" class="mr-auto text-danger-600" wire:click="markMeeting({{ $selected->id }}, 'cancelled')" wire:confirm="¿Cancelar esta demo?">Cancelar demo</x-ui.button>
                <x-ui.button variant="secondary" icon="user-minus" wire:click="markMeeting({{ $selected->id }}, 'no_show')">No se presentó</x-ui.button>
                <x-ui.button variant="secondary" icon="pencil-square" x-on:click="$dispatch('schedule-demo', { meetingId: {{ $selected->id }} })">Reprogramar</x-ui.button>
                <x-ui.button variant="success" icon="check-circle" wire:click="markMeeting({{ $selected->id }}, 'done')">Realizada</x-ui.button>
            @else
                <x-ui.button variant="secondary" x-on:click="open = false">Cerrar</x-ui.button>
            @endif
        </x-slot:footer>
    </x-ui.modal>

    <livewire:saas.sales.meeting-form />
</div>
