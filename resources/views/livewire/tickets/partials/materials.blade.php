{{-- Materiales usados (descuentan inventario). Variables: $materials, $items, $canWork --}}
@if ($canWork && $t->isOpen())
    <div class="mb-4 grid grid-cols-6 gap-2">
        <x-ui.select wire:model="material.item_id" :options="$items" placeholder="Artículo del inventario..." class="col-span-6" />
        <x-ui.input type="number" step="0.01" min="0.01" max="100000" wire:model="material.quantity" placeholder="Cant." class="col-span-2" />
        <x-ui.select wire:model="material.type" :options="['instalacion' => 'Instalación', 'salida' => 'Salida']" class="col-span-4 sm:col-span-2" />
        <x-ui.input wire:model="material.serial" filter="text" maxlength="120" placeholder="Serie (opcional)" class="col-span-6 sm:col-span-2" />
        <x-ui.button icon="plus" wire:click="addMaterial" class="col-span-6">Registrar material</x-ui.button>
    </div>
@endif
<div class="divide-y divide-slate-100">
    @forelse ($materials as $m)
        <div wire:key="mat{{ $m->id }}" @class(['flex items-center justify-between gap-3 py-2 text-sm', 'opacity-60' => $m->type === 'devolucion'])>
            <div class="min-w-0">
                <div class="truncate font-medium text-slate-800">{{ $m->item?->name }}</div>
                <div class="text-xs text-slate-500">
                    <x-ui.status catalog="inventory_movement_types" :value="$m->type" size="xs" :dot="false" />
                    {{ $m->created_at->format('d/m H:i') }} · {{ $m->user?->name }}{{ $m->serial ? ' · S/N '.$m->serial : '' }}
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="font-semibold {{ $m->quantity < 0 ? 'text-slate-800' : 'text-success-700' }}">{{ $m->quantity < 0 ? '' : '+' }}{{ app(\App\Services\InventoryService::class)->qty(abs($m->quantity)) }} {{ $m->item?->unit }}</span>
                @if ($canWork && in_array($m->type, ['instalacion', 'salida']) && $t->isOpen())
                    <button type="button" wire:click="returnMaterial({{ $m->id }})" wire:confirm="¿Devolver este material al almacén?" class="text-slate-400 hover:text-warning-600" title="Devolver al almacén"><x-heroicon-o-arrow-uturn-left class="h-4 w-4" /></button>
                @endif
            </div>
        </div>
    @empty
        <p class="py-3 text-sm text-slate-500">Sin materiales registrados.</p>
    @endforelse
</div>
