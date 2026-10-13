<div>
    <x-ui.page-header title="Pagos" subtitle="Pagos recibidos, comisiones e importe neto"
                      :breadcrumbs="Route::has('billing.index') ? ['Cobranza' => route('billing.index'), 'Pagos' => null] : []">
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="arrow-down-tray">Exportar</x-ui.button></x-slot:trigger>
            <x-ui.dropdown-item wire:click="export('xlsx')" icon="table-cells">Excel (.xlsx)</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('csv')" icon="document-text">CSV</x-ui.dropdown-item>
            <x-ui.dropdown-item wire:click="export('pdf')" icon="document">PDF</x-ui.dropdown-item>
        </x-ui.dropdown>
        @can('billing.manage')
            <x-ui.button variant="success" icon="banknotes" wire:click="openPayment">Registrar pago</x-ui.button>
        @endcan
    </x-ui.page-header>

    {{-- Totales / comisiones (sección 49) --}}
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.stat label="Importe cobrado (bruto)" :value="money($totals->gross)" icon="banknotes" color="brand" :sub="number_format($totals->n).' pagos aplicados'" />
        <x-ui.stat label="Comisión de pasarelas" :value="money($totals->gateway_fee)" icon="credit-card" color="warning" sub="Mercado Pago, Stripe, PayPal, OXXO" />
        <x-ui.stat label="Comisión plataforma" :value="money($totals->platform_fee)" icon="building-office" color="purple" sub="Comisión WISPCONTROL" />
        <x-ui.stat label="Neto WISP" :value="money($totals->net)" icon="check-badge" color="success" :sub="$totals->gross > 0 ? pct($totals->net / $totals->gross * 100).' del bruto' : null" />
    </div>

    @if ($byMethod->isNotEmpty())
        <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('filterMethod', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterMethod === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterMethod !== ''])>
                Todos los métodos
            </button>
            @foreach ($byMethod as $m)
                <button wire:click="$set('filterMethod', '{{ $m->method }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterMethod === $m->method, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterMethod !== $m->method])>
                    {{ config("wisp.payment_methods.{$m->method}", $m->method) }} <span class="ml-1 opacity-70">{{ money($m->total, false) }} · {{ number_format($m->n) }}</span>
                </button>
            @endforeach
        </div>
    @endif

    <x-ui.card :padding="false">
        <div class="space-y-3 border-b border-slate-100 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
                <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Folio, referencia o cliente..." class="md:col-span-2" />
                <x-ui.input type="date" wire:model.live="filterFrom" title="Desde" />
                <x-ui.input type="date" wire:model.live="filterTo" title="Hasta" />
                <x-ui.select wire:model.live="filterCollector" :options="$collectors" placeholder="Todos los cobradores" />
                <x-ui.select wire:model.live="filterStatus" :options="config('wisp.payment_status')" placeholder="Todos los estados" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.select wire:model.live="filterKind" :options="['payment' => 'Pagos', 'credit' => 'Notas de crédito / bonificaciones']" placeholder="Pagos y créditos" class="w-56" />
                <x-ui.select wire:model.live="filterChannel" :options="['online' => 'En línea (pasarelas)', 'offline' => 'Efectivo / banco']" placeholder="Todos los canales" class="w-48" />
                <div class="flex flex-wrap gap-1">
                    @foreach (['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes', 'mes_anterior' => 'Mes anterior', 'anio' => 'Este año'] as $k => $l)
                        <x-ui.button size="sm" variant="ghost" wire:click="setRange('{{ $k }}')">{{ $l }}</x-ui.button>
                    @endforeach
                </div>
                <x-ui.button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" class="ml-auto">Limpiar</x-ui.button>
            </div>
        </div>

        <x-ui.table>
            <x-slot:head>
                <x-ui.th sort="folio" :sort-by="$sortBy" :sort-dir="$sortDir">Folio</x-ui.th>
                <x-ui.th sort="paid_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th>
                <th>Cliente</th>
                <th>Método</th>
                <th>Cobrador / recibió</th>
                <x-ui.th sort="amount" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Importe</x-ui.th>
                <th class="text-right">Comisiones</th>
                <x-ui.th sort="net_amount" :sort-by="$sortBy" :sort-dir="$sortDir" class="text-right">Neto</x-ui.th>
                <th>Estado</th>
                <th></th>
            </x-slot:head>
            @forelse ($payments as $p)
                <tr wire:key="pay{{ $p->id }}" @class(['opacity-55' => $p->status === 'cancelled'])>
                    <td class="text-xs font-semibold text-slate-700">{{ $p->folio }}
                        @if ($p->cashCut)<div class="font-normal text-slate-400">Corte {{ $p->cashCut->folio }}</div>@endif
                    </td>
                    <td class="text-xs text-slate-600">{{ $p->paid_at->format('d/m/Y') }}<div class="text-slate-400">{{ $p->paid_at->format('H:i') }}</div></td>
                    <td>
                        @if ($p->client)
                            <a href="{{ route('clients.show', $p->client_id) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $p->client->full_name }}</a>
                            <div class="text-xs text-slate-500">#{{ $p->client->code }}</div>
                        @else
                            <span class="text-xs text-slate-400">Cliente eliminado</span>
                        @endif
                    </td>
                    <td class="text-xs">
                        <span class="font-medium text-slate-700">{{ $p->methodLabel() }}</span>
                        @if ($p->reference)<div class="max-w-[150px] truncate text-slate-400" title="{{ $p->reference }}">Ref. {{ $p->reference }}</div>@endif
                        @if ($p->evidence_path)<a href="{{ Storage::disk('public')->url($p->evidence_path) }}" target="_blank" class="link inline-flex items-center gap-0.5"><x-heroicon-o-photo class="h-3.5 w-3.5" />Evidencia</a>@endif
                    </td>
                    <td class="text-xs text-slate-600">{{ $p->collector?->name ?? $p->receivedBy?->name ?? ($p->gateway ? 'Pago en línea' : 'Sistema') }}</td>
                    <td class="text-right font-semibold {{ $p->kind === 'credit' ? 'text-teal-700' : 'text-slate-800' }}">{{ money($p->amount) }}</td>
                    <td class="text-right text-xs text-slate-500">
                        @if ($p->gateway_fee > 0 || $p->platform_fee > 0)
                            @if ($p->gateway_fee > 0)<div title="Comisión pasarela">−{{ money($p->gateway_fee) }}</div>@endif
                            @if ($p->platform_fee > 0)<div class="text-purple-600" title="Comisión plataforma">−{{ money($p->platform_fee) }}</div>@endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right font-semibold text-success-700">{{ money($p->net_amount) }}</td>
                    <td><x-ui.status catalog="payment_status" :value="$p->status" size="xs" /></td>
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('documents.receipt', $p) }}" target="_blank" class="inline-block p-1 text-slate-400 hover:text-brand-600" title="Comprobante PDF"><x-heroicon-o-printer class="h-4 w-4" /></a>
                        @can('billing.manage')
                            @if ($p->status === 'completed' && $p->method !== 'saldo')
                                <button wire:click="confirmCancel({{ $p->id }})" class="p-1 text-slate-400 hover:text-danger-600" title="Cancelar pago"><x-heroicon-o-x-circle class="h-4 w-4" /></button>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="10" icon="receipt-percent" title="Sin pagos" description="No hay pagos con estos filtros." />
            @endforelse
            <x-slot:footer>
                <tr>
                    <td colspan="5" class="px-4 py-3 text-xs text-slate-600">Totales {{ $filterStatus ? '('.\App\Support\Catalog::label('payment_status', $filterStatus).')' : '(pagos aplicados)' }} · {{ number_format($totals->n) }} registros</td>
                    <td class="px-4 py-3 text-right text-sm">{{ money($totals->gross) }}</td>
                    <td class="px-4 py-3 text-right text-xs text-slate-500">−{{ money($totals->gateway_fee + $totals->platform_fee) }}</td>
                    <td class="px-4 py-3 text-right text-sm text-success-700">{{ money($totals->net) }}</td>
                    <td colspan="2"></td>
                </tr>
            </x-slot:footer>
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $payments->links() }}</div>
    </x-ui.card>

    <x-ui.modal wire:model="showCancel" title="Cancelar pago" size="md">
        @if ($cancelPaymentModel)
            <div class="mb-4 rounded-xl bg-danger-50 p-3 text-sm text-danger-700">
                Se cancelará el pago <b>{{ $cancelPaymentModel->folio }}</b> de <b>{{ money($cancelPaymentModel->amount) }}</b>
                ({{ $cancelPaymentModel->client?->full_name }}). Su aplicación a los cargos se revierte y el saldo del cliente aumenta.
                @if ($cancelPaymentModel->cash_cut_id) El corte de caja se recalcula. @endif
            </div>
        @endif
        <x-ui.textarea label="Motivo" wire:model="cancelReason" name="cancelReason" rows="2" maxlength="150" required />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Volver</x-ui.button>
            <x-ui.button variant="danger" icon="x-circle" wire:click="cancelPayment">Cancelar pago</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @include('livewire.billing.partials.payment-modal')
</div>
