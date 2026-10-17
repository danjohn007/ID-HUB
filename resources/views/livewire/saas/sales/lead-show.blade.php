@php
    use App\Models\DemoActivity;
    use App\Models\DemoMeeting;
    use App\Models\DemoRequest;
    $l = $lead;
    $plan = $l->tenant?->saasPlan ?? $l->planOrSuggested();
    $order = array_keys(DemoRequest::STAGES);
    $current = array_search($l->status, $order, true);
@endphp
<div>
    <x-ui.page-header :title="$l->company ?: $l->name" :subtitle="$l->company ? $l->name : null" :breadcrumbs="['Prospectos' => route('saas.sales.leads'), ($l->company ?: $l->name) => null]">
        @if ($wa = $l->whatsappUrl())
            <x-ui.button variant="secondary" icon="chat-bubble-oval-left" :href="$wa" :navigate="false" target="_blank" rel="noopener">WhatsApp</x-ui.button>
        @endif
        @if ($l->email)
            <x-ui.button variant="secondary" icon="envelope" :href="'mailto:'.$l->email" :navigate="false">Correo</x-ui.button>
        @endif
        @if ($l->isOpen())
            <x-ui.button variant="secondary" icon="calendar-days" x-on:click="$dispatch('schedule-demo', { leadId: {{ $l->id }} })">Agendar demo</x-ui.button>
        @endif
        @if (! $l->tenant_id && $l->status !== 'lost')
            <x-ui.button icon="rocket-launch" :href="route('saas.dashboard', ['prospecto' => $l->id])">Iniciar prueba</x-ui.button>
        @endif
    </x-ui.page-header>

    {{-- Etapas --}}
    <x-ui.card class="mb-5" :padding="false">
        <div class="flex flex-wrap items-center gap-1.5 p-3">
            @foreach (DemoRequest::STAGES as $stage => $label)
                @php $i = $loop->index; @endphp
                <button type="button" wire:click="move({{ $l->id }}, '{{ $stage }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition',
                            'bg-navy-900 text-white' => $stage === $l->status && ! in_array($stage, ['won', 'lost'], true),
                            'bg-success-600 text-white' => $stage === 'won' && $l->status === 'won',
                            'bg-danger-600 text-white' => $stage === 'lost' && $l->status === 'lost',
                            'bg-brand-50 text-brand-700 hover:bg-brand-100' => $stage !== $l->status && $l->status !== 'lost' && $i < $current && $i < 6,
                            'bg-slate-100 text-slate-500 hover:bg-slate-200' => $stage !== $l->status && ! ($l->status !== 'lost' && $i < $current && $i < 6),
                        ])>
                    @if ($stage !== $l->status && $l->status !== 'lost' && $i < $current && $i < 6)<x-heroicon-m-check class="h-3.5 w-3.5" />@endif
                    {{ $label }}
                </button>
            @endforeach
            <span class="ml-auto text-xs text-slate-400">
                @if ($l->status === 'lost' && $l->lost_reason) Motivo: {{ DemoRequest::LOST_REASONS[$l->lost_reason] ?? $l->lost_reason }} · @endif
                En esta etapa {{ ($l->stage_changed_at ?? $l->created_at)->diffForHumans() }}
            </span>
        </div>
    </x-ui.card>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            {{-- Siguiente paso --}}
            @if ($l->isOpen())
                <x-ui.card title="Siguiente paso" :class="$l->isOverdue() ? 'ring-2 ring-danger-200' : ''">
                    @if ($l->isOverdue())
                        <p class="mb-3 flex items-center gap-1.5 text-sm font-semibold text-danger-600"><x-heroicon-m-exclamation-triangle class="h-4 w-4" /> Vencido {{ $l->next_action_at->diffForHumans() }}</p>
                    @endif
                    <form wire:submit="saveNext" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_210px_auto] sm:items-end">
                        <x-ui.input label="¿Qué sigue?" wire:model="next.action" maxlength="190" placeholder="Llamar para confirmar la demo" />
                        <x-ui.input label="¿Cuándo?" type="datetime-local" wire:model="next.at" />
                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="secondary" icon="check">Guardar</x-ui.button>
                            @if ($l->next_action)
                                <x-ui.button variant="success" icon="check-circle" wire:click="completeNext" title="Marcar como hecho">Hecho</x-ui.button>
                            @endif
                        </div>
                    </form>
                </x-ui.card>
            @endif

            {{-- Historial --}}
            <x-ui.card title="Historial">
                <form wire:submit="addActivity" class="mb-5 rounded-xl bg-slate-50 p-3">
                    <div class="mb-2 flex flex-wrap gap-1.5">
                        @foreach (DemoActivity::TYPES as $type => $label)
                            <button type="button" wire:click="$set('activity.type', '{{ $type }}')" @class(['inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition', 'bg-white text-brand-700 shadow-xs ring-1 ring-brand-200' => $activity['type'] === $type, 'text-slate-500 hover:bg-white' => $activity['type'] !== $type])>
                                <x-dynamic-component :component="'heroicon-m-'.DemoActivity::ICONS[$type]" class="h-3.5 w-3.5" /> {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <x-ui.textarea wire:model="activity.body" rows="2" maxlength="2000" :placeholder="['note' => 'Escribe una nota...', 'call' => '¿Qué se habló en la llamada?', 'whatsapp' => 'Resumen de la conversación por WhatsApp...', 'email' => '¿Qué se le envió o respondió?'][$activity['type']] ?? ''" />
                    <div class="mt-2 flex justify-end"><x-ui.button type="submit" size="sm" icon="plus">Registrar</x-ui.button></div>
                </form>

                <ol class="relative space-y-4 border-l border-slate-200 pl-5">
                    @foreach ($activities as $a)
                        <li wire:key="a{{ $a->id }}" class="relative">
                            <span @class(['absolute -left-[31px] flex h-6 w-6 items-center justify-center rounded-full ring-4 ring-white',
                                'bg-brand-100 text-brand-700' => in_array($a->type, ['note', 'email'], true),
                                'bg-success-100 text-success-700' => in_array($a->type, ['call', 'whatsapp'], true),
                                'bg-purple-100 text-purple-700' => $a->type === 'meeting',
                                'bg-slate-100 text-slate-600' => in_array($a->type, ['stage', 'system'], true)])>
                                <x-dynamic-component :component="'heroicon-m-'.(DemoActivity::ICONS[$a->type] ?? 'sparkles')" class="h-3.5 w-3.5" />
                            </span>
                            <div class="text-sm whitespace-pre-line text-slate-800">{{ $a->body }}</div>
                            <div class="mt-0.5 text-xs text-slate-400">
                                {{ DemoActivity::TYPES[$a->type] ?? ['meeting' => 'Demo', 'stage' => 'Etapa', 'system' => 'Sistema'][$a->type] ?? $a->type }}
                                · {{ $a->user?->name ?? 'Sistema' }} · {{ $a->created_at->translatedFormat('j M Y, H:i') }}
                            </div>
                        </li>
                    @endforeach
                    <li class="relative">
                        <span class="absolute -left-[31px] flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-white ring-4 ring-white"><x-heroicon-m-inbox-arrow-down class="h-3.5 w-3.5" /></span>
                        <div class="text-sm font-medium text-slate-800">{{ $l->source === 'landing' ? 'Solicitó una demo desde la página web' : 'Prospecto registrado · '.(DemoRequest::SOURCES[$l->source] ?? $l->source) }}</div>
                        @if ($l->message)<div class="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-line text-slate-600">“{{ $l->message }}”</div>@endif
                        <div class="mt-0.5 text-xs text-slate-400">{{ $l->created_at->translatedFormat('j M Y, H:i') }}{{ $l->source === 'landing' && ! $l->mail_sent ? ' · el correo a ventas no se envió' : '' }}</div>
                    </li>
                </ol>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            {{-- Prueba / conversión --}}
            @if ($trial)
                @php $t = $trial['tenant']; @endphp
                <x-ui.card title="Su WISP en la plataforma">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-slate-800">{{ $t->name }}</div>
                            <div class="text-xs text-slate-500">{{ $t->saasPlan?->name ?? 'Sin plan' }} · {{ $t->slug }}</div>
                        </div>
                        <x-ui.badge :color="['active' => 'success', 'trial' => 'warning', 'suspended' => 'danger', 'cancelled' => 'slate'][$t->status] ?? 'slate'" dot>{{ \App\Livewire\Saas\Dashboard::STATUSES[$t->status] ?? $t->status }}</x-ui.badge>
                    </div>
                    @if ($trial['days_left'] !== null)
                        <p @class(['mt-3 rounded-lg px-3 py-2 text-sm font-semibold', 'bg-danger-50 text-danger-700' => $trial['days_left'] <= 3, 'bg-warning-50 text-warning-700' => $trial['days_left'] > 3])>
                            {{ $trial['days_left'] < 0 ? 'La prueba venció hace '.abs($trial['days_left']).' días' : ($trial['days_left'] === 0 ? 'La prueba vence hoy' : 'Quedan '.$trial['days_left'].' días de prueba') }}
                        </p>
                    @endif
                    <div class="mt-4">
                        <div class="mb-1 flex justify-between text-xs font-semibold text-slate-600"><span>Activación</span><span>{{ $trial['score'] }}%</span></div>
                        <x-ui.progress :value="$trial['score']" :color="$trial['score'] >= 75 ? 'success' : ($trial['score'] >= 50 ? 'warning' : 'danger')" />
                    </div>
                    <ul class="mt-3 space-y-2">
                        @foreach ($trial['steps'] as [$label, $done, $detail])
                            <li class="flex items-center gap-2 text-sm">
                                @if ($done)<x-heroicon-s-check-circle class="h-5 w-5 text-success-600" />@else<x-heroicon-o-minus-circle class="h-5 w-5 text-slate-300" />@endif
                                <span @class(['flex-1', 'text-slate-800' => $done, 'text-slate-500' => ! $done])>{{ $label }}</span>
                                <span class="text-xs text-slate-400">{{ $detail }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="arrow-right" wire:click="enter({{ $t->id }})">Operar</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" icon="building-office" :href="route('saas.tenants.show', $t)">Ficha del WISP</x-ui.button>
                    </div>
                </x-ui.card>
            @elseif ($l->status !== 'lost')
                <div class="rounded-2xl bg-gradient-to-br from-navy-900 to-brand-700 p-5 text-white">
                    <div class="text-sm font-bold">¿Listo para probar?</div>
                    <p class="mt-1 text-sm text-navy-100">Crea su WISP con {{ \App\Services\DemoScheduler::config('trial_days') }} días de prueba gratuita; los datos de este prospecto se llenan solos.</p>
                    <x-ui.button variant="white" size="sm" icon="rocket-launch" class="mt-3" :href="route('saas.dashboard', ['prospecto' => $l->id])">Iniciar prueba</x-ui.button>
                </div>
            @endif

            {{-- Datos --}}
            <x-ui.card title="Datos del prospecto">
                <x-slot:actions><x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="openEdit">Editar</x-ui.button></x-slot:actions>
                <dl class="grid grid-cols-2 gap-4">
                    <x-ui.detail label="Contacto" class="col-span-2">{{ $l->name }}</x-ui.detail>
                    <x-ui.detail label="Correo" class="col-span-2">@if ($l->email)<a href="mailto:{{ $l->email }}" class="break-all text-brand-600 hover:underline">{{ $l->email }}</a>@endif</x-ui.detail>
                    <x-ui.detail label="Teléfono">{{ $l->phone }}</x-ui.detail>
                    <x-ui.detail label="Ubicación">{{ $l->location }}</x-ui.detail>
                    <x-ui.detail label="Clientes">{{ $l->clients_count ? number_format($l->clients_count) : $l->clients }}</x-ui.detail>
                    <x-ui.detail label="Usa MikroTik">{{ $l->uses_mikrotik === null ? '' : ($l->uses_mikrotik ? 'Sí' : 'No') }}</x-ui.detail>
                    <x-ui.detail label="Sistema actual">{{ $l->current_software }}</x-ui.detail>
                    <x-ui.detail label="Origen">{{ DemoRequest::SOURCES[$l->source] ?? $l->source }}</x-ui.detail>
                    <x-ui.detail label="Responsable">{{ $l->assignee?->name }}</x-ui.detail>
                    <x-ui.detail label="Recibido">{{ $l->created_at->format('d/m/Y') }}</x-ui.detail>
                    <x-ui.detail :label="$l->tenant_id ? 'Plan' : ($l->saas_plan_id ? 'Plan propuesto' : 'Plan sugerido')" class="col-span-2">
                        @if ($plan){{ $plan->name }} · {{ money($l->mrr(), false) }}/mes @endif
                    </x-ui.detail>
                </dl>
            </x-ui.card>

            {{-- Demos --}}
            <x-ui.card title="Demos" :padding="false">
                @if ($l->isOpen())
                    <x-slot:actions><x-ui.button variant="ghost" size="sm" icon="plus" x-on:click="$dispatch('schedule-demo', { leadId: {{ $l->id }} })">Agendar</x-ui.button></x-slot:actions>
                @endif
                <ul class="divide-y divide-slate-100">
                    @forelse ($meetings as $m)
                        <li wire:key="m{{ $m->id }}" class="px-5 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-800">{{ \Illuminate\Support\Str::ucfirst($m->starts_at->translatedFormat('D j M Y')) }} · {{ $m->starts_at->format('H:i') }}</div>
                                    <div class="text-xs text-slate-500">{{ $m->duration_minutes }} min{{ $m->assignee ? ' · '.$m->assignee->name : '' }}{{ $m->booked_by_prospect ? ' · elegida por el prospecto' : '' }}</div>
                                </div>
                                <x-ui.badge :color="DemoMeeting::STATUS_COLORS[$m->status] ?? 'slate'" size="xs">{{ $m->statusLabel() }}</x-ui.badge>
                            </div>
                            @if ($m->notes)<p class="mt-1 text-xs text-slate-500">{{ $m->notes }}</p>@endif
                            <div class="mt-2 flex flex-wrap items-center gap-1">
                                @if ($m->meeting_url)<x-ui.button size="sm" variant="ghost" icon="video-camera" :href="$m->meeting_url" :navigate="false" target="_blank" rel="noopener">Entrar</x-ui.button>@endif
                                @if ($m->status === 'scheduled')
                                    <x-ui.button size="sm" variant="ghost" icon="check-circle" class="text-success-600" wire:click="markMeeting({{ $m->id }}, 'done')">Realizada</x-ui.button>
                                    <x-ui.dropdown width="w-56">
                                        <x-slot:trigger><x-ui.button variant="ghost" size="sm" icon="ellipsis-horizontal" /></x-slot:trigger>
                                        <x-ui.dropdown-item icon="pencil-square" x-on:click="$dispatch('schedule-demo', { meetingId: {{ $m->id }} })">Reprogramar / editar</x-ui.dropdown-item>
                                        @if ($l->email)<x-ui.dropdown-item icon="paper-airplane" wire:click="resendInvite({{ $m->id }})">Reenviar confirmación</x-ui.dropdown-item>@endif
                                        <x-ui.dropdown-item icon="calendar" :href="$m->googleCalendarUrl()" target="_blank">Agregar a Google Calendar</x-ui.dropdown-item>
                                        <x-ui.dropdown-item icon="arrow-down-tray" :href="route('saas.sales.meeting.ics', $m)">Descargar .ics</x-ui.dropdown-item>
                                        <div class="my-1 border-t border-slate-100"></div>
                                        <x-ui.dropdown-item icon="user-minus" wire:click="markMeeting({{ $m->id }}, 'no_show')">No se presentó</x-ui.dropdown-item>
                                        <x-ui.dropdown-item icon="x-circle" danger wire:click="markMeeting({{ $m->id }}, 'cancelled')">Cancelar demo</x-ui.dropdown-item>
                                    </x-ui.dropdown>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-slate-400">Sin demos agendadas.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <div class="text-right">
                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" wire:click="delete" wire:confirm="¿Eliminar este prospecto con todo su historial y demos?">Eliminar prospecto</x-ui.button>
            </div>
        </div>
    </div>

    {{-- Edición --}}
    <x-ui.modal wire:model="showEdit" title="Datos del prospecto" size="2xl">
        <form wire:submit="saveEdit" id="lead-edit-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Nombre del contacto" wire:model="edit.name" maxlength="120" required />
            <x-ui.input label="Nombre del WISP" wire:model="edit.company" maxlength="160" />
            <x-ui.input label="Teléfono / WhatsApp" type="tel" wire:model="edit.phone" filter="phone" />
            <x-ui.input label="Correo" type="email" wire:model="edit.email" filter="email" maxlength="160" />
            <x-ui.input label="Ciudad / estado" wire:model="edit.location" maxlength="120" />
            <x-ui.select label="Origen" wire:model="edit.source" :options="DemoRequest::SOURCES" required />
            <x-ui.select label="Clientes (rango)" wire:model="edit.clients" :options="array_combine(array_keys(DemoRequest::CLIENT_RANGES), array_keys(DemoRequest::CLIENT_RANGES))" placeholder="Sin dato" />
            <x-ui.input label="Clientes (número exacto)" type="number" min="0" wire:model="edit.clients_count" hint="Si lo sabes, se usa para sugerir el plan" />
            <x-ui.select label="Sistema que usa hoy" wire:model="edit.current_software" :options="array_combine(DemoRequest::SOFTWARE, DemoRequest::SOFTWARE)" placeholder="Sin dato" />
            <x-ui.select label="¿Usa MikroTik?" wire:model="edit.uses_mikrotik" :options="['1' => 'Sí', '0' => 'No']" placeholder="Sin dato" />
            <x-ui.select label="Responsable" wire:model="edit.assigned_to" :options="$users" placeholder="Sin asignar" />
            <div class="hidden sm:block"></div>
            <x-ui.select label="Plan propuesto" wire:model="edit.saas_plan_id" :options="$plans" :placeholder="$suggested ? 'Sugerido: '.$suggested->name : 'Sin plan'" />
            <x-ui.input label="MRR estimado" type="number" step="0.01" min="0" wire:model="edit.estimated_mrr" prefix="$" hint="Vacío = precio del plan" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="lead-edit-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @include('livewire.saas.sales.partials.lost-modal')
    <livewire:saas.sales.meeting-form />
</div>
