@php
    use App\Services\Automation\RuleCatalog as RC;
    $ins = "{ target: null, insert(v) { const t = this.target; if (!t) return; const s = t.selectionStart ?? t.value.length; t.setRangeText(v, s, t.selectionEnd ?? s, 'end'); t.dispatchEvent(new Event('input')); t.focus(); } }";
    $opsFor = fn ($type) => match ($type) {
        'number' => array_intersect_key(RC::OPERATORS, array_flip(['=', '!=', '>', '>=', '<', '<='])),
        'enum' => array_intersect_key(RC::OPERATORS, array_flip(['=', '!=', 'in'])),
        default => array_intersect_key(RC::OPERATORS, array_flip(['=', '!=', 'contains'])),
    };
    $isSystem = $rule?->system;
@endphp
<div>
    <x-ui.page-header :title="$rule ? $rule->name : 'Nueva automatización'"
                      :breadcrumbs="['Automatizaciones' => route('automations.index'), ($rule ? 'Editar' : 'Nueva') => null]">
        <x-ui.button variant="secondary" :href="route('automations.index')">Cancelar</x-ui.button>
        @if ($rule && ! $isSystem)
            <x-ui.button variant="danger" icon="trash" wire:click="delete" wire:confirm="¿Eliminar esta regla? Se borrará también su historial.">Eliminar</x-ui.button>
        @endif
        <x-ui.button icon="check" wire:click="save">Guardar regla</x-ui.button>
    </x-ui.page-header>

    @unless ($rule)
        <div class="mb-5 rounded-xl border border-brand-100 bg-brand-50/60 p-4">
            <div class="mb-2 text-sm font-bold text-navy-900">Empezar desde un ejemplo</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($templates as $key => $t)
                    <x-ui.button size="sm" variant="secondary" icon="bolt" wire:click="applyTemplate('{{ $key }}')">{{ $t['name'] }}</x-ui.button>
                @endforeach
            </div>
        </div>
    @endunless

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div class="min-w-0 space-y-3">
            <x-ui.card>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_auto]">
                    <x-ui.input label="Nombre de la regla" wire:model="form.name" filter="text" maxlength="190" required :disabled="$isSystem" :hint="$isSystem ? 'Regla del sistema: se puede editar y desactivar, pero no eliminar ni renombrar.' : null" />
                    <div class="flex items-end pb-2"><x-ui.toggle wire:model="form.active" label="Regla activa" /></div>
                    <x-ui.input label="Descripción" wire:model="form.description" maxlength="190" class="md:col-span-2" placeholder="¿Qué hace esta regla?" />
                </div>
            </x-ui.card>

            {{-- 1. EVENTO --}}
            <x-ui.card>
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">1</span>
                    <h3 class="text-[15px] font-bold text-navy-900">Evento</h3>
                    <span class="text-xs text-slate-500">¿Cuándo se dispara?</span>
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (config('wisp.automation_events') as $key => $label)
                        <button type="button" wire:click="$set('form.event', '{{ $key }}')"
                                @class(['flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs font-semibold transition',
                                        'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-100' => $form['event'] === $key,
                                        'border-slate-200 text-slate-600 hover:bg-slate-50' => $form['event'] !== $key])>
                            <x-dynamic-component :component="'heroicon-o-'.RC::eventIcon($key)" class="h-4 w-4 shrink-0" />
                            <span>{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-500">Sujeto de la regla: <b>{{ $subjectLabel }}</b>. Las condiciones se evalúan sobre sus datos.</p>
            </x-ui.card>

            <div class="flex justify-center"><x-heroicon-o-arrow-down class="h-5 w-5 text-slate-300" /></div>

            {{-- 2. CONDICIONES --}}
            <x-ui.card>
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-warning-600 text-xs font-bold text-white">2</span>
                    <h3 class="text-[15px] font-bold text-navy-900">Condiciones</h3>
                    <span class="text-xs text-slate-500">Todas deben cumplirse</span>
                </div>
                <div class="space-y-2">
                    @forelse ($form['conditions'] as $i => $c)
                        @php $def = $fields[$c['field']] ?? null; $type = $def[1] ?? 'text'; @endphp
                        <div wire:key="cond-{{ $i }}" class="grid grid-cols-1 items-start gap-2 rounded-lg bg-slate-50 p-2.5 sm:grid-cols-[minmax(0,1fr)_150px_minmax(0,1.2fr)_auto]">
                            <select wire:model.live="form.conditions.{{ $i }}.field" class="form-input text-sm">
                                @foreach ($fields as $f => $fd)<option value="{{ $f }}">{{ $fd[0] }}</option>@endforeach
                            </select>
                            @if ($type === 'bool')
                                <div class="flex items-center px-1 text-xs text-slate-500 sm:col-span-1">es</div>
                                @php $yes = ($c['operator'] ?? '=') === '=' && (string) ($c['value'] ?? '') === '1'; @endphp
                                <div class="grid grid-cols-2 gap-1 rounded-lg bg-white p-1 ring-1 ring-slate-200">
                                    <button type="button" wire:click="setBool({{ $i }}, true)" @class(['rounded-md px-2 py-1 text-xs font-semibold', 'bg-brand-600 text-white' => $yes, 'text-slate-500' => ! $yes])>Sí</button>
                                    <button type="button" wire:click="setBool({{ $i }}, false)" @class(['rounded-md px-2 py-1 text-xs font-semibold', 'bg-brand-600 text-white' => ! $yes, 'text-slate-500' => $yes])>No</button>
                                </div>
                            @else
                                <select wire:model.live="form.conditions.{{ $i }}.operator" class="form-input text-sm">
                                    @foreach ($opsFor($type) as $op => $opLabel)<option value="{{ $op }}">{{ $opLabel }}</option>@endforeach
                                </select>
                                <div>
                                    @if ($type === 'enum' && $c['operator'] === 'in')
                                        @php $sel = array_map('trim', explode(',', (string) $c['value'])); @endphp
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($def[2] as $k => $l)
                                                <button type="button" wire:click="toggleIn({{ $i }}, '{{ $k }}')" @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1', 'bg-brand-600 text-white ring-brand-600' => in_array((string) $k, $sel, true), 'bg-white text-slate-600 ring-slate-200' => ! in_array((string) $k, $sel, true)])>{{ $l }}</button>
                                            @endforeach
                                        </div>
                                    @elseif ($type === 'enum')
                                        <select wire:model.live="form.conditions.{{ $i }}.value" class="form-input text-sm">
                                            <option value="">Selecciona...</option>
                                            @foreach ($def[2] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                                        </select>
                                    @else
                                        <input type="{{ $type === 'number' ? 'number' : 'text' }}" step="any" wire:model.live.debounce.400ms="form.conditions.{{ $i }}.value" maxlength="190" class="form-input text-sm" placeholder="Valor">
                                    @endif
                                    @error("form.conditions.$i.value")<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                                </div>
                            @endif
                            <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="removeCondition({{ $i }})" title="Quitar" />
                        </div>
                    @empty
                        <p class="rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">Sin condiciones: la regla se ejecuta siempre que ocurra el evento.</p>
                    @endforelse
                </div>
                <x-ui.button class="mt-3" size="sm" variant="secondary" icon="plus" wire:click="addCondition">Agregar condición</x-ui.button>
            </x-ui.card>

            <div class="flex justify-center"><x-heroicon-o-arrow-down class="h-5 w-5 text-slate-300" /></div>

            {{-- 3. ACCIONES --}}
            <x-ui.card>
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-success-600 text-xs font-bold text-white">3</span>
                    <h3 class="text-[15px] font-bold text-navy-900">Acciones</h3>
                    <span class="text-xs text-slate-500">Se ejecutan en este orden</span>
                </div>
                @error('form.actions')<p class="mb-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                <div class="space-y-3">
                    @foreach ($form['actions'] as $i => $a)
                        @php $t = $a['type']; $p = "form.actions.$i.params"; @endphp
                        <div wire:key="act-{{ $i }}-{{ $t }}" class="rounded-xl border border-slate-200">
                            <div class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-600">{{ $i + 1 }}</span>
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-success-50 text-success-700"><x-dynamic-component :component="'heroicon-o-'.(RC::ACTION_ICONS[$t] ?? 'bolt')" class="h-4.5 w-4.5" /></span>
                                <span class="flex-1 text-sm font-semibold text-slate-800">{{ config('wisp.automation_actions.'.$t, $t) }}</span>
                                <x-ui.button variant="ghost" size="sm" icon="chevron-up" wire:click="moveAction({{ $i }}, -1)" :disabled="$i === 0" title="Subir" />
                                <x-ui.button variant="ghost" size="sm" icon="chevron-down" wire:click="moveAction({{ $i }}, 1)" :disabled="$i === count($form['actions']) - 1" title="Bajar" />
                                <x-ui.button variant="ghost" size="sm" icon="trash" wire:click="removeAction({{ $i }})" title="Quitar" />
                            </div>
                            @if (in_array($t, ['send_message', 'notify_staff', 'create_ticket', 'create_alert', 'set_status', 'suspend_service', 'reconnect_service', 'change_plan', 'address_list']))
                                <div class="p-3" x-data="{{ $ins }}">
                                    @switch($t)
                                        @case('send_message')
                                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <x-ui.select label="Plantilla (evento)" wire:model="{{ $p }}.event" :options="config('wisp.message_events')" />
                                                <div>
                                                    <label class="form-label">Canales</label>
                                                    <div class="flex flex-wrap gap-3 pt-1.5">
                                                        @foreach (config('wisp.channels') as $ck => $cl)
                                                            <label class="flex items-center gap-1.5 text-sm text-slate-700"><input type="checkbox" value="{{ $ck }}" wire:model="{{ $p }}.channels" class="rounded border-slate-300">{{ $cl }}</label>
                                                        @endforeach
                                                    </div>
                                                    <p class="mt-1 text-xs text-slate-500">Sin seleccionar = canales predeterminados del WISP.</p>
                                                </div>
                                            </div>
                                            @break
                                        @case('notify_staff')
                                            <label class="form-label">Roles a notificar (WhatsApp)</label>
                                            <div class="mb-3 flex flex-wrap gap-3">
                                                @foreach (RC::staffRoles() as $rk => $rl)
                                                    <label class="flex items-center gap-1.5 text-sm text-slate-700"><input type="checkbox" value="{{ $rk }}" wire:model="{{ $p }}.roles" class="rounded border-slate-300">{{ $rl }}</label>
                                                @endforeach
                                            </div>
                                            @error("$p.roles")<p class="-mt-2 mb-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                                            <x-ui.textarea label="Mensaje" wire:model="{{ $p }}.message" rows="2" maxlength="500" x-on:focus="target = $el" />
                                            @break
                                        @case('create_ticket')
                                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <x-ui.select label="Categoría" wire:model="{{ $p }}.category" :options="config('wisp.ticket_categories')" />
                                                <x-ui.select label="Prioridad" wire:model="{{ $p }}.priority" :options="config('wisp.ticket_priorities')" />
                                                <x-ui.input label="Asunto" wire:model="{{ $p }}.subject" maxlength="190" class="md:col-span-2" x-on:focus="target = $el" />
                                                <x-ui.textarea label="Descripción" wire:model="{{ $p }}.description" rows="2" maxlength="1000" class="md:col-span-2" x-on:focus="target = $el" />
                                            </div>
                                            @break
                                        @case('create_alert')
                                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <x-ui.select label="Severidad" wire:model="{{ $p }}.severity" :options="config('wisp.alert_severity')" />
                                                <x-ui.select label="Tipo de alerta" wire:model="{{ $p }}.alert_type" :options="RC::alertTypes()" />
                                                <x-ui.input label="Título" wire:model="{{ $p }}.title" maxlength="190" class="md:col-span-2" x-on:focus="target = $el" />
                                                <x-ui.textarea label="Mensaje" wire:model="{{ $p }}.message" rows="2" maxlength="500" class="md:col-span-2" x-on:focus="target = $el" />
                                            </div>
                                            @break
                                        @case('set_status')
                                            <x-ui.select label="Nuevo estado del cliente" wire:model="{{ $p }}.status" :options="config('wisp.client_status')" class="max-w-xs" />
                                            @break
                                        @case('suspend_service')
                                            <x-ui.toggle wire:model="{{ $p }}.notify" label="Notificar al cliente" description="Envía el aviso de suspensión ejecutada (además de las acciones de mensaje de esta regla)" />
                                            @break
                                        @case('reconnect_service')
                                            <div class="space-y-3">
                                                <x-ui.toggle wire:model="{{ $p }}.require_zero_balance" label="Solo si el saldo está en cero" description="No reactiva si el cliente aún tiene adeudo" />
                                                <x-ui.toggle wire:model="{{ $p }}.notify" label="Notificar al cliente" description="Envía el aviso de reconexión" />
                                            </div>
                                            @break
                                        @case('change_plan')
                                            <div class="space-y-3">
                                                <x-ui.select label="Plan destino" wire:model="{{ $p }}.plan_id" :options="RC::planOptions()" placeholder="Elige un plan..." class="max-w-md" />
                                                <x-ui.toggle wire:model="{{ $p }}.keep_billing" label="Solo cambiar la velocidad" description="Aplica en el MikroTik la velocidad del plan elegido sin cambiar el plan que se factura (ej. reducir velocidad al superar el límite de consumo)" />
                                            </div>
                                            @break
                                        @case('address_list')
                                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <x-ui.select label="Operación" wire:model="{{ $p }}.operation" :options="['add' => 'Agregar la IP del cliente', 'remove' => 'Quitar la IP del cliente']" />
                                                <x-ui.input label="Address-list" wire:model="{{ $p }}.list" maxlength="60" filter="code" placeholder="ej. LIMITE_CONSUMO" hint="Nombre exacto de la lista en /ip firewall address-list" />
                                            </div>
                                            <p class="mt-2 text-xs text-slate-500">Se aplica en el MikroTik del cliente (o el de su repetidor). Úsala con reglas de firewall / mangle del equipo: aviso, redirección o cola especial.</p>
                                            @break
                                    @endswitch
                                    @if (in_array($t, ['notify_staff', 'create_ticket', 'create_alert']))
                                        <div class="mt-2 flex flex-wrap items-center gap-1">
                                            <span class="mr-1 text-[11px] text-slate-500">Insertar:</span>
                                            @foreach (RC::PLACEHOLDERS as $ph)
                                                <button type="button" @mousedown.prevent="insert('{{ $ph }}')" class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 hover:bg-slate-200">{{ $ph }}</button>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if (in_array($t, RC::CLIENT_ACTIONS) && ! in_array($subjectType, ['client', 'ticket']))
                                        <p class="mt-2 text-xs text-warning-700">Esta acción requiere un cliente; con el sujeto "{{ $subjectLabel }}" se omitirá.</p>
                                    @endif
                                </div>
                            @elseif ($t === 'assign_on_call')
                                <p class="px-3 py-2.5 text-xs text-slate-500">Asigna el ticket creado en un paso anterior al técnico responsable del repetidor, al técnico de guardia o al de menor carga.</p>
                            @elseif ($t === 'add_late_fee')
                                <p class="px-3 py-2.5 text-xs text-slate-500">Aplica el recargo configurado en cobranza (una vez por periodo).</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.select wire:model="newAction" :options="config('wisp.automation_actions')" placeholder="Elige una acción..." class="min-w-[240px]" />
                    <x-ui.button variant="secondary" icon="plus" wire:click="addAction">Agregar acción</x-ui.button>
                </div>
            </x-ui.card>
        </div>

        {{-- Panel derecho: prueba y ejecuciones --}}
        <div class="space-y-5">
            <x-ui.card title="Probar regla" subtitle="Evalúa las condiciones contra un caso real sin ejecutar acciones">
                <div class="space-y-3">
                    <div>
                        <label class="form-label">{{ $subjectLabel }}</label>
                        @if ($subjectId && $subjectName)
                            <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                                <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800">{{ $subjectName }}</span>
                                <x-ui.button size="sm" variant="ghost" icon="x-mark" wire:click="$set('subjectId', null)" />
                            </div>
                        @else
                            <x-ui.search wire:model.live.debounce.300ms="subjectSearch" placeholder="Buscar {{ mb_strtolower($subjectLabel) }}..." />
                            @if ($subjectResults)
                                <div class="mt-1 max-h-56 overflow-y-auto rounded-lg border border-slate-200">
                                    @foreach ($subjectResults as $sid => $slabel)
                                        <button type="button" wire:click="selectSubject({{ $sid }})" class="block w-full truncate px-3 py-1.5 text-left text-xs text-slate-700 hover:bg-slate-50">{{ $slabel }}</button>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                    @if ($contextFields)
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($contextFields as $ck => $cl)
                                <x-ui.input :label="$cl" wire:model="context.{{ $ck }}" class="text-xs" placeholder="opcional" />
                            @endforeach
                        </div>
                        <p class="text-[11px] text-slate-500">Datos que el evento aporta al dispararse. Si los dejas vacíos se calculan del sujeto cuando es posible.</p>
                    @endif
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" icon="beaker" wire:click="dryRun" class="flex-1">Probar</x-ui.button>
                        <x-ui.button variant="warning" icon="play" wire:click="runNow" class="flex-1" :disabled="! $rule"
                                     wire:confirm="Se ejecutarán las acciones REALES de la regla guardada (suspender, enviar mensajes, crear tickets...). ¿Continuar?">Ejecutar ahora</x-ui.button>
                    </div>
                    @unless ($rule)<p class="text-[11px] text-slate-500">Guarda la regla para poder ejecutarla manualmente.</p>@endunless
                </div>

                @if ($test)
                    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                        <div @class(['flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold', 'bg-success-50 text-success-700' => $test['matches'], 'bg-danger-50 text-danger-700' => ! $test['matches']])>
                            @if ($test['matches'])<x-heroicon-o-check-circle class="h-5 w-5" /> Las condiciones se cumplen @else<x-heroicon-o-x-circle class="h-5 w-5" /> Las condiciones NO se cumplen @endif
                        </div>
                        @if ($test['conditions'])
                            <div class="space-y-1">
                                @foreach ($test['conditions'] as $c)
                                    <div class="flex items-start gap-2 text-xs">
                                        @if ($c['ok'])<x-heroicon-s-check-circle class="h-4 w-4 shrink-0 text-success-600" />@else<x-heroicon-s-x-circle class="h-4 w-4 shrink-0 text-danger-600" />@endif
                                        <span class="text-slate-700">{{ $c['text'] }} <span class="text-slate-400">(actual: {{ $c['actual'] }})</span></span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div>
                            <div class="mb-1 text-xs font-semibold text-slate-600">{{ $test['matches'] ? 'Se ejecutarían:' : 'No se ejecutaría:' }}</div>
                            <ol class="list-decimal space-y-0.5 pl-5 text-xs text-slate-600">
                                @foreach ($test['actions'] as $a)<li>{{ $a }}</li>@endforeach
                            </ol>
                        </div>
                        <details class="text-xs">
                            <summary class="cursor-pointer font-semibold text-slate-600">Hechos evaluados ({{ count($test['facts']) }})</summary>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                                @foreach ($test['facts'] as $f)
                                    <dt class="text-slate-500">{{ $f['label'] }}</dt><dd class="font-medium text-slate-800">{{ $f['value'] }}</dd>
                                @endforeach
                            </dl>
                        </details>
                    </div>
                @endif

                @if ($runResult)
                    @php [$rl, $rc] = ['success' => ['Ejecutada', 'success'], 'skipped' => ['Omitida', 'slate'], 'failed' => ['Con errores', 'danger']][$runResult['status']] ?? [$runResult['status'], 'slate']; @endphp
                    <div class="mt-4 rounded-lg border border-slate-200 p-3">
                        <div class="mb-1.5 flex items-center gap-2 text-xs font-semibold text-slate-700">Resultado de la ejecución <x-ui.badge :color="$rc" size="xs">{{ $rl }}</x-ui.badge></div>
                        <ul class="space-y-0.5 text-xs text-slate-600">
                            @foreach ($runResult['log'] as $line)<li>• {{ $line }}</li>@endforeach
                        </ul>
                    </div>
                @endif
            </x-ui.card>

            @if ($rule)
                <x-ui.card title="Últimas ejecuciones" :padding="false">
                    <x-slot:actions><span class="text-xs text-slate-500">{{ number_format($rule->run_count) }} en total</span></x-slot:actions>
                    <div class="max-h-[420px] divide-y divide-slate-100 overflow-y-auto">
                        @forelse ($runs as $run)
                            @php [$rl, $rc] = ['success' => ['Ejecutada', 'success'], 'skipped' => ['Omitida', 'slate'], 'failed' => ['Error', 'danger']][$run->status] ?? [$run->status, 'slate']; $u = RC::subjectUrl($run->subject_type, $run->subject_id); @endphp
                            <div class="px-4 py-2.5 text-xs">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-slate-500">{{ $run->created_at->format('d/m/Y H:i') }}</span>
                                    <x-ui.badge :color="$rc" size="xs">{{ $rl }}</x-ui.badge>
                                </div>
                                <div class="mt-0.5 text-slate-600">
                                    {{ RC::SUBJECTS[$run->subject_type] ?? $run->subject_type }}
                                    @if ($u)<a href="{{ $u }}" wire:navigate class="link">#{{ $run->subject_id }}</a>@else #{{ $run->subject_id }} @endif
                                    — {{ implode(' · ', $run->log ?? []) }}
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-xs text-slate-500">Esta regla aún no se ha ejecutado.</p>
                        @endforelse
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
