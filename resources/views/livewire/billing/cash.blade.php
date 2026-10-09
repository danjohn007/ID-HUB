<div>
    <x-ui.page-header title="Cortes de caja" subtitle="Control de efectivo: cobros registrados vs. efectivo entregado por cobrador"
                      :breadcrumbs="Route::has('billing.index') && auth()->user()->can('billing.view') ? ['Cobranza' => route('billing.index'), 'Cortes de caja' => null] : []">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        <x-ui.button icon="plus" wire:click="openCreate">Nuevo corte</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat label="Efectivo en campo" :value="money($kpi['in_field'], false)" icon="banknotes" color="brand" :sub="$kpi['open'].' cortes abiertos'" />
        <x-ui.stat label="Por conciliar" :value="money($kpi['to_reconcile_amount'], false)" icon="clipboard-document-check" color="warning" :sub="$kpi['to_reconcile'].' entregas pendientes de conciliar'" />
        <x-ui.stat label="Conciliado este mes" :value="money($kpi['reconciled_month'], false)" icon="check-badge" color="success" />
        <x-ui.stat label="Diferencias del mes" :value="money($kpi['diff_month'])" icon="scale" :color="abs($kpi['diff_month']) > 0.009 ? 'danger' : 'slate'" sub="Entregado − esperado" />
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Folio..." />
            <x-ui.select wire:model.live="filterCollector" :options="$collectors" placeholder="Todos los cobradores" />
            <x-ui.select wire:model.live="filterStatus" :options="['open' => 'Abiertos', 'closed' => 'Cerrados · por entregar', 'delivered' => 'Entregados', 'reconciled' => 'Conciliados', 'diff' => 'Con diferencia']" placeholder="Todos los estados" />
        </div>
        <x-ui.table>
            <x-slot:head>
                <th>Folio</th>
                <th>Cobrador</th>
                <x-ui.th sort="opened_at" :sort-by="$sortBy" :sort-dir="$sortDir">Periodo</x-ui.th>
                <th class="text-center">Cobros</th>
                <x-ui.th sort="expected_amount" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Esperado</x-ui.th>
                <th class="text-right">Entregado</th>
                <x-ui.th sort="difference" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Diferencia</x-ui.th>
                <th>Estado</th>
                <th class="text-right">Acciones</th>
            </x-slot:head>
            @forelse ($cuts as $c)
                @php $st = $c->displayStatus(); @endphp
                <tr wire:key="cut{{ $c->id }}">
                    <td><button wire:click="view({{ $c->id }})" class="text-sm font-semibold text-brand-600 hover:underline">{{ $c->folio }}</button></td>
                    <td><div class="flex items-center gap-2"><x-ui.avatar :name="$c->collector?->name" size="sm" color="purple" /><span class="text-sm font-medium text-slate-700">{{ $c->collector?->name }}</span></div></td>
                    <td class="text-xs text-slate-600">{{ $c->opened_at->format('d/m/Y') }} → {{ $c->closed_at?->format('d/m/Y H:i') ?? 'en curso' }}</td>
                    <td class="text-center text-sm text-slate-700">{{ $c->payments_count }}</td>
                    <td class="text-right font-semibold text-slate-800">{{ money($c->expected_amount) }}</td>
                    <td class="text-right text-slate-700">{{ $c->delivered_amount !== null ? money($c->delivered_amount) : '—' }}</td>
                    <td class="text-right font-semibold">
                        @if ($c->difference === null)<span class="text-slate-400">—</span>
                        @else<span @class(['text-success-700' => abs((float) $c->difference) < 0.01, 'text-danger-600' => $c->difference < -0.009, 'text-warning-600' => $c->difference > 0.009])>{{ money($c->difference) }}</span>@endif
                    </td>
                    <td>
                        <x-ui.status catalog="cash_cut_status" :value="$st" size="xs" />
                        @if ($c->reconciledBy)<div class="mt-0.5 text-[11px] text-slate-400">{{ $c->reconciledBy->name }}</div>@endif
                    </td>
                    <td class="text-right whitespace-nowrap">
                        @if ($st === 'open')
                            <x-ui.button size="sm" variant="secondary" icon="lock-closed" wire:click="closeCut({{ $c->id }})" wire:confirm="¿Cerrar el corte {{ $c->folio }}? Se asignarán los cobros en efectivo pendientes del cobrador.">Cerrar</x-ui.button>
                        @elseif ($st === 'closed')
                            <x-ui.button size="sm" variant="warning" icon="banknotes" wire:click="openDeliver({{ $c->id }})">Registrar entrega</x-ui.button>
                        @elseif ($st === 'delivered')
                            <x-ui.button size="sm" variant="success" icon="check" wire:click="openReconcile({{ $c->id }})">Conciliar</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="ghost" icon="eye" wire:click="view({{ $c->id }})" title="Ver detalle" />
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="9" icon="calculator" title="Sin cortes de caja" description="Crea un corte para un cobrador con cobros en efectivo." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $cuts->links() }}</div>
    </x-ui.card>

    {{-- Detalle --}}
    <x-ui.modal wire:model="showDetail" :title="$detail ? 'Corte '.$detail->folio : 'Corte de caja'" size="4xl">
        @if ($detail)
            @php $st = $detail->displayStatus(); @endphp
            <div class="mb-4 grid grid-cols-2 gap-4 md:grid-cols-5">
                <x-ui.detail label="Cobrador">{{ $detail->collector?->name }}</x-ui.detail>
                <x-ui.detail label="Estado"><x-ui.status catalog="cash_cut_status" :value="$st" size="xs" /></x-ui.detail>
                <x-ui.detail label="Apertura">{{ $detail->opened_at->format('d/m/Y H:i') }}</x-ui.detail>
                <x-ui.detail label="Cierre">{{ $detail->closed_at?->format('d/m/Y H:i') }}</x-ui.detail>
                <x-ui.detail label="Conciliado">{{ $detail->reconciled_at ? $detail->reconciled_at->format('d/m/Y H:i').' · '.$detail->reconciledBy?->name : '' }}</x-ui.detail>
            </div>
            <div class="mb-4 grid grid-cols-3 gap-3">
                <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Cobros registrados</div><div class="text-lg font-bold text-navy-900">{{ money($detail->expected_amount) }}</div><div class="text-[11px] text-slate-400">{{ $detail->payments_count }} pagos en efectivo</div></div>
                <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-500">Efectivo entregado</div><div class="text-lg font-bold text-navy-900">{{ $detail->delivered_amount !== null ? money($detail->delivered_amount) : '—' }}</div></div>
                <div @class(['rounded-xl p-3', 'bg-slate-50' => $detail->difference === null || abs((float) $detail->difference) < 0.01, 'bg-danger-50' => $detail->difference !== null && abs((float) $detail->difference) >= 0.01])>
                    <div class="text-xs text-slate-500">Diferencia</div>
                    <div @class(['text-lg font-bold', 'text-navy-900' => $detail->difference === null || abs((float) $detail->difference) < 0.01, 'text-danger-600' => $detail->difference !== null && abs((float) $detail->difference) >= 0.01])>{{ $detail->difference !== null ? money($detail->difference) : '—' }}</div>
                </div>
            </div>
            @if ($detail->notes)
                <div class="mb-4 rounded-xl border border-slate-200 p-3 text-xs whitespace-pre-line text-slate-600">{{ $detail->notes }}</div>
            @endif
            <div class="overflow-hidden rounded-xl border border-slate-200">
                <x-ui.table class="max-h-[360px] overflow-y-auto">
                    <x-slot:head><th>Folio</th><th>Fecha</th><th>Cliente</th><th>Estado</th><th class="text-right">Importe</th><th></th></x-slot:head>
                    @forelse ($detailPayments as $p)
                        <tr wire:key="dp{{ $p->id }}" @class(['opacity-50' => $p->status !== 'completed'])>
                            <td class="text-xs font-semibold text-slate-700">{{ $p->folio }}</td>
                            <td class="text-xs text-slate-600">{{ $p->paid_at->format('d/m/Y H:i') }}</td>
                            <td class="text-sm">{{ $p->client?->full_name }} <span class="text-xs text-slate-400">#{{ $p->client?->code }}</span></td>
                            <td><x-ui.status catalog="payment_status" :value="$p->status" size="xs" /></td>
                            <td class="text-right font-semibold">{{ money($p->amount) }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if ($p->evidence_path)<a href="{{ Storage::disk('public')->url($p->evidence_path) }}" target="_blank" class="inline-block p-1 text-slate-400 hover:text-brand-600" title="Evidencia"><x-heroicon-o-photo class="h-4 w-4" /></a>@endif
                                <a href="{{ route('documents.receipt', $p) }}" target="_blank" class="inline-block p-1 text-slate-400 hover:text-brand-600" title="Comprobante"><x-heroicon-o-printer class="h-4 w-4" /></a>
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="banknotes" title="Sin cobros en este corte" />
                    @endforelse
                </x-ui.table>
            </div>
        @endif
        <x-slot:footer>
            @if ($detail)
                @if ($detail->displayStatus() === 'open')
                    <x-ui.button variant="secondary" icon="lock-closed" wire:click="closeCut({{ $detail->id }})" wire:confirm="¿Cerrar el corte?">Cerrar corte</x-ui.button>
                @endif
                @if (in_array($detail->displayStatus(), ['open', 'closed']))
                    <x-ui.button variant="warning" icon="banknotes" wire:click="openDeliver({{ $detail->id }})">Registrar entrega</x-ui.button>
                @elseif ($detail->displayStatus() === 'delivered')
                    <x-ui.button variant="success" icon="check" wire:click="openReconcile({{ $detail->id }})">Conciliar</x-ui.button>
                @endif
            @endif
            <x-ui.button variant="secondary" @click="open = false">Cerrar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Registrar entrega --}}
    <x-ui.modal wire:model="showDeliver" title="Registrar entrega de efectivo" size="md">
        @if ($actionCut)
            <div class="mb-4 rounded-xl bg-slate-50 p-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Corte</span><b>{{ $actionCut->folio }} · {{ $actionCut->collector?->name }}</b></div>
                <div class="mt-1 flex justify-between"><span class="text-slate-500">Cobros registrados (esperado)</span><b>{{ money($actionCut->expected_amount) }}</b></div>
                @if (! $actionCut->closed_at)<p class="mt-2 text-xs text-warning-700">El corte se cerrará y se recalculará el esperado con los cobros pendientes del cobrador.</p>@endif
            </div>
            <div class="space-y-3">
                <x-ui.input label="Efectivo entregado" type="number" step="0.01" min="0" prefix="$" wire:model.live.debounce.400ms="deliveredAmount" name="deliveredAmount" required />
                @if (is_numeric($deliveredAmount))
                    @php $d = round((float) $deliveredAmount - (float) $actionCut->expected_amount, 2); @endphp
                    <p @class(['text-sm font-semibold', 'text-success-700' => abs($d) < 0.01, 'text-danger-600' => $d < -0.009, 'text-warning-600' => $d > 0.009])>
                        {{ abs($d) < 0.01 ? 'Sin diferencia' : ($d < 0 ? 'Faltante: ' : 'Sobrante: ').money(abs($d)) }}
                    </p>
                @endif
                <x-ui.textarea label="Notas" wire:model="notes" name="notes" rows="2" placeholder="Denominaciones, observaciones..." />
            </div>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="warning" icon="banknotes" wire:click="deliver">Registrar entrega</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Conciliar --}}
    <x-ui.modal wire:model="showReconcile" title="Conciliar corte de caja" size="md">
        @if ($actionCut)
            <div class="mb-4 space-y-1 rounded-xl bg-slate-50 p-3 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Corte</span><b>{{ $actionCut->folio }} · {{ $actionCut->collector?->name }}</b></div>
                <div class="flex justify-between"><span class="text-slate-500">Cobros registrados</span><b>{{ money($actionCut->expected_amount) }}</b></div>
                <div class="flex justify-between"><span class="text-slate-500">Efectivo entregado</span><b>{{ money($actionCut->delivered_amount) }}</b></div>
                <div class="flex justify-between"><span class="text-slate-500">Diferencia</span><b class="{{ abs((float) $actionCut->difference) > 0.009 ? 'text-danger-600' : 'text-success-700' }}">{{ money($actionCut->difference) }}</b></div>
            </div>
            <x-ui.textarea :label="abs((float) $actionCut->difference) > 0.009 ? 'Justificación de la diferencia' : 'Notas (opcional)'" wire:model="notes" name="notes" rows="2" />
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="success" icon="check" wire:click="reconcile">Conciliar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Nuevo corte --}}
    <x-ui.modal wire:model="showCreate" title="Nuevo corte de caja" size="md">
        <div class="space-y-3">
            <x-ui.select label="Cobrador" wire:model.live="newCollector" name="newCollector" :options="$collectors" placeholder="Selecciona..." required />
            @if ($pendingForNew)
                <div class="rounded-xl bg-brand-50 p-3 text-sm text-brand-700">
                    @if ($pendingForNew['open'])
                        Ya tiene el corte abierto <b>{{ $pendingForNew['open']->folio }}</b> ({{ money($pendingForNew['open']->expected_amount) }}). Se le agregarán
                    @else
                        Se creará un corte con
                    @endif
                    <b>{{ $pendingForNew['count'] }}</b> cobros en efectivo sin corte por <b>{{ money($pendingForNew['amount']) }}</b> (desde {{ $pendingForNew['from']->format('d/m/Y H:i') }}).
                </div>
            @endif
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="plus" wire:click="createCut">Crear corte</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
