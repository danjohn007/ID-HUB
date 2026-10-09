{{--
    <x-ui.dropdown> <x-slot:trigger><x-ui.button variant="secondary" icon="ellipsis-vertical" /></x-slot:trigger>
        <x-ui.dropdown-item wire:click="x" icon="pencil">Editar</x-ui.dropdown-item>
    </x-ui.dropdown>
--}}
@props(['trigger', 'align' => 'right', 'width' => 'w-52'])
<div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <div @click="open = !open">{{ $trigger }}</div>
    <div x-cloak x-show="open" x-transition @click="open = false"
         class="absolute z-30 mt-1.5 {{ $width }} {{ $align === 'right' ? 'right-0' : 'left-0' }} rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
        {{ $slot }}
    </div>
</div>
