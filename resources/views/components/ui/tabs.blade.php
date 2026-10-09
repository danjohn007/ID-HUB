{{--
    Pestañas ligadas a una propiedad Livewire:
    <x-ui.tabs wire:model.live="tab" :tabs="['resumen' => 'Resumen', 'pagos' => 'Pagos']" />
--}}
@props(['tabs' => []])
@php $model = $attributes->wire('model')->value(); @endphp
<div x-data="{ tab: @entangle($model).live }" {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'border-b border-slate-200']) }}>
    <nav class="-mb-px flex gap-1 overflow-x-auto">
        @foreach ($tabs as $key => $label)
            <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                    class="whitespace-nowrap border-b-2 px-3.5 py-2.5 text-sm font-semibold transition">
                {{ $label }}
            </button>
        @endforeach
    </nav>
</div>
