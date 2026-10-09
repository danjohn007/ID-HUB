{{-- <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar..." /> --}}
@props(['placeholder' => 'Buscar...'])
<div {{ $attributes->only('class')->merge(['class' => 'relative']) }}>
    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
    <input type="search" placeholder="{{ $placeholder }}" {{ $attributes->except('class')->merge(['class' => 'form-input pl-9']) }}>
</div>
