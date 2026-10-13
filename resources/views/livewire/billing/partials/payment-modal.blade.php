{{-- Modal "Registrar pago" (trait App\Livewire\Billing\Concerns\RegistersPayments) --}}
<x-ui.modal wire:model="showPayment" title="Registrar pago" size="xl">
    @php $pc = $this->payClient; @endphp
    <div class="space-y-4">
        @if ($pc)
            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <x-ui.avatar :name="$pc->full_name" />
                <div class="min-w-0 flex-1">
                    <div class="truncate font-semibold text-slate-800">{{ $pc->full_name }}</div>
                    <div class="text-xs text-slate-500">#{{ $pc->code }} · {{ $pc->plan?->name ?? 'Sin plan' }} · <x-ui.status catalog="client_status" :value="$pc->service_status" size="xs" /></div>
                </div>
                <div class="text-right">
                    <div class="text-[11px] text-slate-500">Saldo</div>
                    <div class="font-bold {{ $pc->balance > 0 ? 'text-danger-600' : 'text-success-700' }}">{{ money($pc->balance) }}</div>
                </div>
                <button type="button" wire:click="clearPayClient" class="rounded-lg p-1 text-slate-400 hover:bg-white hover:text-slate-600" title="Cambiar cliente"><x-heroicon-o-x-mark class="h-4 w-4" /></button>
            </div>
        @else
            <div>
                <label class="form-label">Cliente <span class="text-danger-600">*</span></label>
                <x-ui.search wire:model.live.debounce.300ms="payClientSearch" placeholder="Nombre, código, teléfono, IP..." autofocus />
                @error('payClientId')<p class="mt-1 text-xs font-medium text-danger-600">Selecciona un cliente.</p>@enderror
                @if (mb_strlen(trim($payClientSearch)) >= 2)
                    <div class="mt-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
                        @forelse ($this->payClientResults as $r)
                            <button type="button" wire:key="pcr{{ $r->id }}" wire:click="selectPayClient({{ $r->id }})" class="flex w-full items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50">
                                <x-ui.avatar :name="$r->full_name" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-slate-800">{{ $r->full_name }}</span>
                                    <span class="block truncate text-xs text-slate-500">#{{ $r->code }} · {{ $r->address }}</span>
                                </span>
                                <span class="text-right text-sm font-semibold {{ $r->balance > 0 ? 'text-danger-600' : 'text-slate-500' }}">{{ money($r->balance) }}</span>
                            </button>
                        @empty
                            <p class="px-3 py-4 text-center text-xs text-slate-500">Sin coincidencias</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @endif

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <x-ui.input label="Importe" type="number" step="0.01" min="1" max="1000000" prefix="$" wire:model="pay.amount" name="pay.amount" required />
            <x-ui.select label="Método" wire:model.live="pay.method" name="pay.method" :options="collect(config('wisp.payment_methods'))->except(['saldo', 'referido'])" required />
            @if ($pay['method'] === 'efectivo')
                <x-ui.select label="Cobrador (corte de caja)" wire:model="pay.collector_id" :options="$this->collectorOptions" placeholder="Cobro en oficina (sin cobrador)" hint="Si lo recibió un cobrador, se suma a su corte abierto." />
            @else
                <x-ui.input label="Referencia" wire:model="pay.reference" name="pay.reference" filter="text" maxlength="120" placeholder="Folio, autorización..." />
            @endif
            <x-ui.input label="Notas" wire:model="pay.notes" name="pay.notes" maxlength="190" />
        </div>
        <x-ui.toggle wire:model="pay.notify" label="Enviar confirmación al cliente" description="WhatsApp/SMS con el folio del pago." />
        <p class="text-xs text-slate-500">El pago se aplica a los cargos más antiguos; el excedente queda como saldo a favor. Si el cliente está suspendido y liquida su saldo, se reactiva automáticamente.</p>
    </div>
    <x-slot:footer>
        <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
        <x-ui.button variant="success" icon="banknotes" wire:click="savePayment">Registrar pago</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
