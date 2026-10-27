<div>
    <x-ui.page-header title="Cobranza" subtitle="Cartera, cargos pendientes, cobranza automática y promesas de pago">
        @if (in_array($tab, ['cartera', 'cargos']))
            <x-ui.dropdown>
                <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
                <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
                <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
                <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
            </x-ui.dropdown>
        @endif
        @if (Route::has('billing.payments'))
            <x-ui.button variant="secondary" icon="receipt-percent" :href="route('billing.payments')">Pagos</x-ui.button>
        @endif
        @can('billing.manage')
            <x-ui.button variant="success" icon="banknotes" wire:click="openPayment">Registrar pago</x-ui.button>
        @endcan
    </x-ui.page-header>

    {{-- KPIs --}}
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat label="Por cobrar" :value="money($kpi['receivable'], false)" icon="banknotes" color="brand" sub="Saldo de cargos abiertos" />
        <x-ui.stat label="Vencido" :value="money($kpi['overdue'], false)" icon="exclamation-triangle" color="danger" :sub="number_format($kpi['morosos'] + $kpi['suspendidos']).' clientes · '.pct($kpi['rate']).' morosidad'" />
        @php $chg = $kpi['collected_prev'] > 0 ? round(($kpi['collected'] - $kpi['collected_prev']) / $kpi['collected_prev'] * 100, 1) : null; @endphp
        <x-ui.stat label="Cobrado del mes" :value="money($kpi['collected'], false)" icon="currency-dollar" color="success" :trend="$chg !== null ? ($chg >= 0 ? '+' : '').$chg.'% vs mismo corte mes anterior' : null" :trend-up="$chg !== null ? $chg >= 0 : null" />
        <x-ui.stat label="Recuperación de cartera" :value="pct($kpi['recovery'])" icon="arrow-trending-up" color="purple" sub="Vencido recuperado este mes" />
    </div>
    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-ui.stat variant="soft" label="Morosos" :value="number_format($kpi['morosos'])" icon="clock" color="warning" sub="Con pago vencido, en tolerancia" />
        <x-ui.stat variant="soft" label="Suspendidos" :value="number_format($kpi['suspendidos'])" icon="no-symbol" color="danger" sub="Servicio cortado por falta de pago" />
        <x-ui.stat variant="soft" label="Promesas de pago vigentes" :value="number_format($kpi['promises'])" icon="hand-raised" color="teal" :sub="money($kpi['promises_amount']).' comprometidos'" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-4" :tabs="['cartera' => 'Cartera vencida', 'cargos' => 'Cargos pendientes', 'automatica' => 'Cobranza automática', 'promesas' => 'Promesas de pago']" />

    {{-- ======================================================= Cartera vencida --}}
    @if ($tab === 'cartera')
        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach (\App\Livewire\Billing\Index::AGING as $key => $range)
                @php $b = $agingBuckets[$key] ?? null; @endphp
                <button type="button" wire:click="$set('filterAging', '{{ $filterAging === $key ? '' : $key }}')"
                        @class(['card flex items-center justify-between gap-3 p-3.5 text-left transition hover:border-brand-200', 'border-brand-500 ring-2 ring-brand-100' => $filterAging === $key])>
                    <span>
                        <span class="block text-xs font-medium text-slate-500">{{ $key === '90+' ? 'Más de 90 días' : $key.' días' }}</span>
                        <span class="block text-lg font-bold text-navy-900">{{ money($b->amount ?? 0, false) }}</span>
                    </span>
                    <span @class(['rounded-full px-2 py-0.5 text-xs font-bold', 'bg-warning-50 text-warning-700' => $key === '1-30', 'bg-danger-50 text-danger-600' => $key !== '1-30'])>{{ number_format($b->n ?? 0) }}</span>
                </button>
            @endforeach
        </div>

        <x-ui.card :padding="false">
            <div class="filter-bar">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Cliente, código, teléfono..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Todas las zonas" />
                <x-ui.select wire:model.live="filterStatus" :options="collect(config('wisp.client_status'))->only(['activo', 'moroso', 'suspendido', 'en_revision'])" placeholder="Todos los estados" />
                <x-ui.select wire:model.live="filterCollector" :options="['none' => 'Sin cobrador'] + $collectors->all()" placeholder="Todos los cobradores" />
                <div class="flex gap-2">
                    <x-ui.select wire:model.live="filterRisk" :options="collect(config('wisp.risk_levels'))->map(fn ($r) => 'Riesgo '.$r[0])" placeholder="Riesgo" class="flex-1" />
                    @if ($search || $filterZone || $filterStatus || $filterCollector || $filterRisk || $filterAging)
                        <x-ui.button variant="ghost" icon="x-mark" wire:click="clearFilters" title="Limpiar filtros" />
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-xs text-slate-600">
                <span><b class="text-slate-800">{{ number_format($portfolioTotals->n) }}</b> clientes con saldo vencido @if ($filterAging) · atraso {{ $filterAging }} días @endif</span>
                <span>Total vencido: <b class="text-danger-600">{{ money($portfolioTotals->amount) }}</b></span>
            </div>

            <x-ui.table stack>
                <x-slot:head>
                    <th>Cliente</th>
                    <th>Zona / Cobrador</th>
                    <th>Estado</th>
                    <x-ui.th sort="overdue_amount" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Vencido</x-ui.th>
                    <x-ui.th sort="overdue_days" :sort-by="$sortBy" :sort-dir="$sortDir">Atraso</x-ui.th>
                    <th>Riesgo</th>
                    <x-ui.th sort="last_payment_at" :sort-by="$sortBy" :sort-dir="$sortDir">Último pago</x-ui.th>
                    <th class="text-right">Acciones</th>
                </x-slot:head>
                @forelse ($portfolio as $c)
                    <tr wire:key="pf{{ $c->id }}">
                        <td>
                            <a href="{{ route('clients.show', $c) }}" wire:navigate class="flex items-center gap-3">
                                <x-ui.avatar :name="$c->full_name" />
                                <span class="min-w-0">
                                    <span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $c->full_name }}</span>
                                    <span class="block text-xs text-slate-500">#{{ $c->code }} · {{ $c->phone ?: 'Sin teléfono' }}</span>
                                </span>
                            </a>
                        </td>
                        <td class="text-xs" data-label="Zona / Cobrador"><div class="text-slate-700">{{ $c->zone?->name ?? '—' }}</div><div class="text-slate-500">{{ $c->collector?->name ?? 'Sin cobrador' }}</div></td>
                        <td data-label="Estado"><x-ui.status catalog="client_status" :value="$c->service_status" /></td>
                        <td class="text-right" data-label="Vencido">
                            <div class="font-bold text-danger-600">{{ money($c->overdue_amount) }}</div>
                            @if ((float) $c->balance > (float) $c->overdue_amount + 0.009)<div class="text-2xs text-slate-500">Total {{ money($c->balance) }}</div>@endif
                        </td>
                        <td data-label="Atraso">
                            @php $d = (int) $c->overdue_days; @endphp
                            <x-ui.badge :color="$d > 60 ? 'danger' : ($d > 30 ? 'warning' : 'slate')">{{ $d }} {{ $d === 1 ? 'día' : 'días' }}</x-ui.badge>
                            <div class="mt-0.5 text-2xs text-slate-400">{{ $c->overdue_charges }} {{ $c->overdue_charges == 1 ? 'cargo' : 'cargos' }}</div>
                        </td>
                        <td data-label="Riesgo"><x-ui.status catalog="risk_levels" :value="$c->risk_level" :dot="false" size="xs" /></td>
                        <td class="text-xs text-slate-600" data-label="Último pago">{{ $c->last_payment_at ? \Carbon\Carbon::parse($c->last_payment_at)->format('d/m/Y') : 'Nunca' }}
                            @if ($c->last_payment_at)<div class="text-slate-400">{{ \Carbon\Carbon::parse($c->last_payment_at)->diffForHumans() }}</div>@endif
                        </td>
                        <td class="text-right whitespace-nowrap max-md:pt-2">
                            @can('billing.manage')
                                <x-ui.button size="sm" variant="success" icon="banknotes" wire:click="openPayment({{ $c->id }})" title="Registrar pago">Pago</x-ui.button>
                            @endcan
                            <x-ui.dropdown>
                                <x-slot:trigger><x-ui.button size="sm" variant="ghost" icon="ellipsis-vertical" /></x-slot:trigger>
                                @can('billing.manage')
                                    <x-ui.dropdown-item icon="link" wire:click="sendLink({{ $c->id }})">Enviar enlace "Pagar ahora"</x-ui.dropdown-item>
                                    <x-ui.dropdown-item icon="bell-alert" wire:click="sendReminder({{ $c->id }})">Enviar recordatorio</x-ui.dropdown-item>
                                @endcan
                                <x-ui.dropdown-item icon="user" :href="route('clients.show', $c)">Ver ficha</x-ui.dropdown-item>
                                <x-ui.dropdown-item icon="document-chart-bar" :href="route('documents.statement', $c)" target="_blank">Estado de cuenta (PDF)</x-ui.dropdown-item>
                            </x-ui.dropdown>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="check-badge" title="Sin cartera vencida" description="No hay clientes con saldo vencido para estos filtros." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $portfolio->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ======================================================= Cargos pendientes --}}
    @if ($tab === 'cargos')
        <x-ui.card :padding="false">
            <div class="filter-bar">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Folio o cliente..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterDue" :options="['vencidos' => 'Vencidos', 'hoy' => 'Vencen hoy', 'semana' => 'Próximos 7 días', 'futuros' => 'Por vencer']" placeholder="Cualquier vencimiento" />
                <x-ui.select wire:model.live="filterType" :options="config('wisp.charge_types')" placeholder="Todos los tipos" />
                <x-ui.select wire:model.live="filterZone" :options="$zones" placeholder="Todas las zonas" />
                <div class="flex gap-2">
                    <x-ui.input type="date" wire:model.live="filterFrom" title="Vence desde" class="flex-1" />
                    <x-ui.input type="date" wire:model.live="filterTo" title="Vence hasta" class="flex-1" />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-xs text-slate-600">
                <span><b class="text-slate-800">{{ number_format($chargesCount) }}</b> cargos abiertos
                    @if ($search || $filterDue || $filterType || $filterZone || $filterFrom || $filterTo)
                        · <button wire:click="clearFilters" class="link">Limpiar filtros</button>
                    @endif
                </span>
                <span>Saldo: <b class="text-slate-800">{{ money($chargesTotal) }}</b></span>
            </div>
            <x-ui.table stack>
                <x-slot:head>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>Concepto</th>
                    <x-ui.th sort="due_date" :sort-by="$sortBy" :sort-dir="$sortDir">Vence</x-ui.th>
                    <x-ui.th sort="total" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Total</x-ui.th>
                    <th class="text-right">Saldo</th>
                    <th>Estado</th>
                    <th></th>
                </x-slot:head>
                @forelse ($charges as $ch)
                    <tr wire:key="ch{{ $ch->id }}">
                        <td class="text-xs font-medium text-slate-700" data-label="Folio">{{ $ch->folio }}</td>
                        <td>
                            @if ($ch->client)
                                <a href="{{ route('clients.show', $ch->client_id) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $ch->client->full_name }}</a>
                                <div class="text-xs text-slate-500">#{{ $ch->client->code }} · {{ $ch->client->zone?->name ?? 'Sin zona' }}</div>
                            @endif
                        </td>
                        <td class="text-xs" data-label="Concepto"><div class="text-slate-700">{{ $ch->description }}</div><div class="text-slate-400">{{ \App\Support\Catalog::label('charge_types', $ch->type) }}</div></td>
                        <td class="text-xs" data-label="Vence">
                            <span @class(['font-semibold', 'text-danger-600' => $ch->isOverdue(), 'text-warning-600' => $ch->due_date->isToday(), 'text-slate-700' => ! $ch->isOverdue() && ! $ch->due_date->isToday()])>{{ $ch->due_date->format('d/m/Y') }}</span>
                            <div class="text-slate-400">{{ $ch->due_date->isToday() ? 'Hoy' : $ch->due_date->diffForHumans() }}</div>
                        </td>
                        <td class="text-right text-slate-700" data-label="Total">{{ money($ch->total) }}</td>
                        <td class="text-right font-semibold {{ $ch->isOverdue() ? 'text-danger-600' : 'text-slate-800' }}" data-label="Saldo">{{ money($ch->balance()) }}</td>
                        <td data-label="Estado"><x-ui.status catalog="charge_status" :value="$ch->status" size="xs" /></td>
                        <td class="text-right max-md:pt-1">
                            @can('billing.manage')
                                <x-ui.button size="sm" variant="ghost" icon="banknotes" wire:click="openPayment({{ $ch->client_id }})" title="Registrar pago" />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="document-check" title="Sin cargos pendientes" description="No hay cargos abiertos con estos filtros." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $charges->links() }}</div>
        </x-ui.card>
    @endif

    {{-- ======================================================= Cobranza automática --}}
    @if ($tab === 'automatica')
        @php
            $yes = fn ($v) => $v ? 'Sí' : 'No';
            $runLabel = fn ($r) => $r ? \Carbon\Carbon::parse($r['at'])->format('d/m/Y H:i') : 'Sin ejecuciones registradas';
        @endphp
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Política de cobranza y corte" subtitle="Configuración vigente del WISP (sección 8)">
                    <x-slot:actions>
                        @if (Route::has('settings.index'))
                            <x-ui.button size="sm" variant="secondary" icon="cog-6-tooth" :href="route('settings.index')">Configurar</x-ui.button>
                        @endif
                    </x-slot:actions>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 sm:grid-cols-3">
                        <x-ui.detail label="Modo de facturación">{{ $policy['mode'] === 'anniversary' ? 'Aniversario (día de alta)' : 'Día fijo' }}</x-ui.detail>
                        <x-ui.detail label="Día de corte (emisión)">{{ $policy['mode'] === 'anniversary' ? 'Según cliente' : 'Día '.$policy['billing_day'] }}</x-ui.detail>
                        <x-ui.detail label="Fecha límite">{{ $policy['due_days'] }} días después de emitido</x-ui.detail>
                        <x-ui.detail label="Días de tolerancia">{{ $policy['grace_days'] }} días</x-ui.detail>
                        <x-ui.detail label="Hora de corte">{{ $policy['cut_hour'] }} h</x-ui.detail>
                        <x-ui.detail label="Recordatorio previo">{{ $policy['reminder_days_before'] }} días antes · {{ $policy['reminder_hour'] ?? '09:00' }} h</x-ui.detail>
                        <x-ui.detail label="Recargo por pago tardío">{{ money($policy['late_fee']) }}</x-ui.detail>
                        <x-ui.detail label="Cargo de reconexión">{{ money($policy['reconnection_fee']) }}</x-ui.detail>
                        <x-ui.detail label="Tipo de bloqueo">{{ $policy['block_type'] === 'partial' ? 'Bloqueo parcial (velocidad reducida / aviso)' : 'Bloqueo total' }}</x-ui.detail>
                        <x-ui.detail label="Suspensión automática">{{ $yes($policy['auto_suspend']) }}</x-ui.detail>
                        <x-ui.detail label="Reconexión automática">{{ $yes($policy['auto_reconnect']) }}</x-ui.detail>
                        <x-ui.detail label="Reconectar solo con saldo liquidado">{{ $yes($policy['reconnect_requires_full_payment']) }}</x-ui.detail>
                    </dl>

                    <div class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div class="rounded-xl bg-danger-50/60 p-4">
                            <div class="mb-2 text-xs font-bold tracking-wide text-danger-700 uppercase">Flujo de corte</div>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs font-medium text-slate-700">
                                @foreach (['Fecha límite vencida', '+'.$policy['grace_days'].' días de tolerancia', 'Suspensión a las '.$policy['cut_hour'], 'MikroTik bloquea'] as $step)
                                    <span class="rounded-lg bg-white px-2 py-1 shadow-xs">{{ $step }}</span>@if (! $loop->last)<x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-danger-400" />@endif
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-xl bg-success-50/60 p-4">
                            <div class="mb-2 text-xs font-bold tracking-wide text-success-700 uppercase">Flujo de reconexión</div>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs font-medium text-slate-700">
                                @foreach (['Pago recibido', 'Validación', 'Aplicación de pago', 'MikroTik habilita'] as $step)
                                    <span class="rounded-lg bg-white px-2 py-1 shadow-xs">{{ $step }}</span>@if (! $loop->last)<x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-success-500" />@endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Reglas de automatización de cobranza" :padding="false">
                    <x-slot:actions>
                        @if (Route::has('automations.index'))
                            <x-ui.button size="sm" variant="ghost" icon="bolt" :href="route('automations.index')">Automatizaciones</x-ui.button>
                        @endif
                    </x-slot:actions>
                    <div class="divide-y divide-slate-100">
                        @forelse ($rules as $rule)
                            <div class="flex items-start gap-3 px-5 py-3">
                                <span @class(['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', 'bg-success-50 text-success-600' => $rule->active, 'bg-slate-100 text-slate-400' => ! $rule->active])><x-heroicon-o-bolt class="h-4 w-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-slate-800">{{ $rule->name }}</div>
                                    <div class="text-xs text-slate-500">Cuando: {{ config("wisp.automation_events.{$rule->event}", $rule->event) }}
                                        → {{ collect($rule->actions ?? [])->map(fn ($a) => config('wisp.automation_actions.'.($a['type'] ?? ''), $a['type'] ?? ''))->implode(', ') }}</div>
                                </div>
                                <x-ui.badge :color="$rule->active ? 'success' : 'slate'" size="xs">{{ $rule->active ? 'Activa' : 'Inactiva' }}</x-ui.badge>
                            </div>
                        @empty
                            <x-ui.empty icon="bolt" title="Sin reglas de cobranza" />
                        @endforelse
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-5">
                <x-ui.card title="Ejecutar ahora">
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-semibold text-slate-800">Generar cargos del periodo</div>
                            <p class="mt-0.5 text-xs text-slate-500">Crea las mensualidades que correspondan a hoy. Es seguro repetirlo: no duplica cargos.</p>
                            <p class="mt-1 text-2xs text-slate-400">Última ejecución: {{ $runLabel($lastRuns['generate']) }}@if ($lastRuns['generate']) · {{ $lastRuns['generate']['stats']['charges'] ?? 0 }} cargos @endif</p>
                            @can('billing.manage')
                                <x-ui.button class="mt-2 w-full" variant="secondary" icon="document-plus" wire:click="runGenerate" wire:confirm="¿Generar los cargos del periodo para hoy?">Generar cargos del periodo ahora</x-ui.button>
                            @endcan
                        </div>
                        <div class="border-t border-slate-100 pt-4">
                            <div class="text-sm font-semibold text-slate-800">Enviar recordatorios</div>
                            <p class="mt-0.5 text-xs text-slate-500">Pendientes ahora: {{ $reminderPreview['reminders'] }} recordatorios, {{ $reminderPreview['due'] }} avisos de vencimiento, {{ $reminderPreview['warnings'] }} avisos de suspensión, {{ $reminderPreview['morosos'] }} por marcar morosos.</p>
                            <p class="mt-1 text-2xs text-slate-400">Última ejecución: {{ $runLabel($lastRuns['reminders']) }}</p>
                            @can('billing.manage')
                                <x-ui.button class="mt-2 w-full" variant="secondary" icon="paper-airplane" wire:click="runReminders" wire:confirm="¿Enviar ahora los recordatorios pendientes?">Enviar recordatorios ahora</x-ui.button>
                            @endcan
                        </div>
                        <div class="border-t border-slate-100 pt-4">
                            <div class="text-sm font-semibold text-slate-800">Corte automático</div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                @if (! empty($preview['skipped']))
                                    {{ $preview['skipped'] }}.
                                @else
                                    <b class="{{ $preview['evaluated'] ? 'text-danger-600' : 'text-success-700' }}">{{ $preview['evaluated'] }}</b> clientes fuera de tolerancia pendientes de procesar hoy. Se ejecuta cada 15 minutos a partir de las {{ $policy['cut_hour'] }} h.
                                @endif
                            </p>
                            <p class="mt-1 text-2xs text-slate-400">Última ejecución: {{ $runLabel($lastRuns['cutoffs']) }}@if ($lastRuns['cutoffs']) · {{ $lastRuns['cutoffs']['stats']['suspended'] ?? 0 }} suspendidos @endif</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Pagos en línea" subtitle='Enlace "Pagar ahora" (sección 7)'>
                    @forelse ($gateways as $g)
                        <div class="flex items-center justify-between py-1.5 text-sm">
                            <span class="font-medium text-slate-700">{{ config("wisp.gateways.{$g->provider}", $g->provider) }}</span>
                            <span class="flex items-center gap-1.5">
                                @if ($gatewayService->isSimulated($g))<x-ui.badge color="warning" size="xs">Simulador</x-ui.badge>@elseif ($g->sandbox)<x-ui.badge color="info" size="xs">Sandbox</x-ui.badge>@else<x-ui.badge color="success" size="xs">Producción</x-ui.badge>@endif
                                <span class="text-xs text-slate-400">{{ (float) $g->fee_percent }}% + {{ money($g->fee_fixed) }}</span>
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No hay pasarelas habilitadas. Los clientes no podrán pagar en línea.</p>
                    @endforelse
                    @if (Route::has('settings.index'))
                        <a href="{{ route('settings.index') }}" wire:navigate class="link mt-2 inline-block text-xs">Configurar pasarelas →</a>
                    @endif
                </x-ui.card>
            </div>
        </div>
    @endif

    {{-- ======================================================= Promesas de pago --}}
    @if ($tab === 'promesas')
        <x-ui.card :padding="false">
            <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Cliente..." class="md:col-span-2" />
                <x-ui.select wire:model.live="filterPromise" :options="['vigente' => 'Vigentes', 'incumplida' => 'Incumplidas', 'cumplida' => 'Cumplidas']" placeholder="Todas" />
                <x-ui.select wire:model.live="filterCollector" :options="$collectors" placeholder="Todos los cobradores" />
            </div>
            <x-ui.table>
                <x-slot:head>
                    <th>Cliente</th>
                    <th>Cobrador</th>
                    <x-ui.th sort="visited_at" :sort-by="$sortBy" :sort-dir="$sortDir">Visita</x-ui.th>
                    <x-ui.th sort="promise_date" :sort-by="$sortBy" :sort-dir="$sortDir">Promete pagar</x-ui.th>
                    <th class="text-right">Monto prometido</th>
                    <th class="text-right">Saldo actual</th>
                    <th>Estado</th>
                    <th></th>
                </x-slot:head>
                @forelse ($promises as $v)
                    @php $st = $v->fulfilled_at ? 'cumplida' : ($v->promise_date->lt(today()) ? 'incumplida' : 'vigente'); @endphp
                    <tr wire:key="pr{{ $v->id }}">
                        <td>
                            @if ($v->client)
                                <a href="{{ route('clients.show', $v->client_id) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $v->client->full_name }}</a>
                                <div class="text-xs text-slate-500">#{{ $v->client->code }} · {{ $v->client->zone?->name }}</div>
                            @endif
                        </td>
                        <td class="text-xs text-slate-700">{{ $v->collector?->name }}</td>
                        <td class="text-xs text-slate-600">{{ $v->visited_at->format('d/m/Y H:i') }}@if ($v->notes)<div class="max-w-[200px] truncate text-slate-400" title="{{ $v->notes }}">{{ $v->notes }}</div>@endif</td>
                        <td class="text-xs"><span class="font-semibold text-slate-800">{{ $v->promise_date->format('d/m/Y') }}</span><div class="text-slate-400">{{ $v->promise_date->isToday() ? 'Hoy' : $v->promise_date->diffForHumans() }}</div></td>
                        <td class="text-right font-semibold text-slate-700">{{ $v->promise_amount ? money($v->promise_amount) : '—' }}</td>
                        <td class="text-right font-semibold {{ ($v->client?->balance ?? 0) > 0 ? 'text-danger-600' : 'text-success-700' }}">{{ money($v->client?->balance ?? 0) }}</td>
                        <td>
                            <x-ui.status catalog="promise_status" :value="$st" size="xs" />
                            @if ($v->fulfilled_at)<div class="mt-0.5 text-2xs text-slate-400">Pagó {{ \Carbon\Carbon::parse($v->fulfilled_at)->format('d/m/Y') }}</div>@endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @can('billing.manage')
                                @if ($st !== 'cumplida')
                                    <x-ui.button size="sm" variant="ghost" icon="banknotes" wire:click="openPayment({{ $v->client_id }})" title="Registrar pago" />
                                    <x-ui.button size="sm" variant="ghost" icon="bell-alert" wire:click="sendReminder({{ $v->client_id }})" title="Enviar recordatorio" />
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="hand-raised" title="Sin promesas de pago" description="Las promesas se registran desde la app del cobrador." />
                @endforelse
            </x-ui.table>
            <div class="border-t border-slate-100 px-4 py-3">{{ $promises->links() }}</div>
        </x-ui.card>
    @endif

    @include('livewire.billing.partials.payment-modal')
</div>
