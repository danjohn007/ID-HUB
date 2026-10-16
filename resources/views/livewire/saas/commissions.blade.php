<div>
    <x-ui.page-header title="Comisiones" subtitle="Pagos en línea por WISP, comisión de la plataforma y facturación mensual">
        <x-ui.select wire:model.live="month" :options="$months" class="w-48" />
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Pagos en línea" :value="money($totals['amount'], false)" icon="credit-card" color="brand" :sub="number_format($totals['n']).' pagos en el mes'" />
        <x-ui.stat label="Comisión gateways" :value="money($totals['gateway_fee'])" icon="building-library" color="slate" />
        <x-ui.stat label="Comisión ID-Hub" :value="money($totals['platform_fee'])" icon="banknotes" color="success" />
        <x-ui.stat label="MRR de suscripciones" :value="money($mrr, false)" icon="arrow-trending-up" color="purple" sub="WISP activos con plan" />
    </div>

    <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <x-ui.card :padding="false" title="Por WISP" subtitle="Ejemplo: pago $450 → comisión $15 → neto WISP $435">
            <x-ui.table>
                <x-slot:head>
                    <th>WISP</th>
                    <th class="text-right">Pagos</th>
                    <th class="text-right">Importe</th>
                    <th class="text-right">Gateway</th>
                    <th class="text-right">ID-Hub</th>
                    <th class="text-right">Neto WISP</th>
                </x-slot:head>
                @forelse ($rows as $r)
                    <tr wire:key="r{{ $r->tenant->id }}" @class(['text-slate-400' => ! $r->n])>
                        <td>
                            <div class="font-semibold text-slate-800">{{ $r->tenant->name }}</div>
                            <div class="text-xs text-slate-400">{{ $r->tenant->saasPlan?->name ?? 'Sin plan' }}@if ($r->tenant->saasPlan) · {{ (float) $r->tenant->saasPlan->commission_percent }}%@endif</div>
                        </td>
                        <td class="text-right">{{ number_format($r->n) }}</td>
                        <td class="text-right font-semibold text-slate-800">{{ money($r->amount) }}</td>
                        <td class="text-right text-slate-500">{{ money($r->gateway_fee) }}</td>
                        <td class="text-right font-semibold text-success-700">{{ money($r->platform_fee) }}</td>
                        <td class="text-right">{{ money($r->net) }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="6" icon="banknotes" title="Sin WISP" />
                @endforelse
                @if ($rows->isNotEmpty())
                    <tr class="bg-slate-50 font-semibold">
                        <td>Total</td>
                        <td class="text-right">{{ number_format($totals['n']) }}</td>
                        <td class="text-right">{{ money($totals['amount']) }}</td>
                        <td class="text-right">{{ money($totals['gateway_fee']) }}</td>
                        <td class="text-right text-success-700">{{ money($totals['platform_fee']) }}</td>
                        <td class="text-right">{{ money($totals['net']) }}</td>
                    </tr>
                @endif
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Comisión ID-Hub" subtitle="Últimos 12 meses">
            <x-ui.chart id="fees-chart" :options="$chart" height="250px" />
        </x-ui.card>
    </div>

    <x-ui.card :padding="false" title="Facturas SaaS del mes" subtitle="Mensualidad del plan + comisiones de plataforma">
        <x-slot:actions>
            <x-ui.button size="sm" icon="document-plus" wire:click="generateInvoices" wire:confirm="¿Generar o recalcular las facturas pendientes de este mes?">Generar facturas</x-ui.button>
        </x-slot:actions>
        <x-ui.table>
            <x-slot:head>
                <th>Folio</th>
                <th>WISP</th>
                <th>Plan</th>
                <th class="text-right">Mensualidad</th>
                <th class="text-right">Comisiones</th>
                <th class="text-right">Total</th>
                <th>Estado</th>
                <th></th>
            </x-slot:head>
            @forelse ($invoices as $inv)
                <tr wire:key="i{{ $inv->id }}">
                    <td class="font-mono text-xs text-slate-600">{{ $inv->folio }}</td>
                    <td class="font-semibold text-slate-800">{{ $inv->tenant?->name }}</td>
                    <td class="text-sm text-slate-600">{{ $inv->saasPlan?->name ?? '—' }}</td>
                    <td class="text-right">{{ money($inv->amount) }}</td>
                    <td class="text-right">{{ money($inv->commissions) }}</td>
                    <td class="text-right font-bold text-navy-900">{{ money($inv->total) }}</td>
                    <td>
                        <x-ui.badge :color="['pending' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'slate'][$inv->status] ?? 'slate'" dot>
                            {{ ['pending' => 'Pendiente', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada'][$inv->status] ?? $inv->status }}
                        </x-ui.badge>
                        @if ($inv->paid_at)<div class="mt-0.5 text-xs text-slate-400">{{ $inv->paid_at->format('d/m/Y') }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        @if (in_array($inv->status, ['pending', 'overdue']))
                            <x-ui.button variant="ghost" size="sm" icon="check-circle" class="text-success-600" wire:click="markPaid({{ $inv->id }})" wire:confirm="¿Marcar {{ $inv->folio }} como pagada?" title="Marcar pagada" />
                        @endif
                        @if ($inv->status === 'pending')
                            <x-ui.button variant="ghost" size="sm" icon="x-circle" class="text-danger-600" wire:click="cancelInvoice({{ $inv->id }})" wire:confirm="¿Cancelar {{ $inv->folio }}?" title="Cancelar" />
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="8" icon="document-text" title="Sin facturas este mes" description="Se generan solas el día 1 de cada mes, o con el botón Generar facturas." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
