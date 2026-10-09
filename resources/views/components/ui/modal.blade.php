{{--
    Modal controlado por una propiedad booleana de Livewire:
    <x-ui.modal wire:model="showForm" title="Nuevo cliente" size="2xl"> ... <x-slot:footer>...</x-slot:footer> </x-ui.modal>
    size: md | lg | xl | 2xl | 3xl | 4xl
--}}
@props(['title' => null, 'size' => 'lg', 'footer' => null])
@php
    $width = ['md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '3xl' => 'max-w-3xl', '4xl' => 'max-w-4xl', '5xl' => 'max-w-5xl'][$size] ?? 'max-w-lg';
    $model = $attributes->wire('model')->value();
@endphp
<div x-data="{ open: @entangle($model).live }" x-show="open" x-cloak x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-navy-950/50 backdrop-blur-[2px]" @click="open = false"></div>
    <div class="flex min-h-full items-start justify-center p-4 sm:items-center sm:p-6">
        <div x-show="open" x-transition class="relative w-full {{ $width }} rounded-2xl bg-white shadow-2xl">
            @if ($title)
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                    <h3 class="text-base font-bold text-navy-900">{{ $title }}</h3>
                    <button type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="open = false">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>
            @endif
            <div class="max-h-[75vh] overflow-y-auto px-6 py-5">
                {{ $slot }}
            </div>
            @if ($footer)
                <div class="flex flex-wrap items-center justify-end gap-2 rounded-b-2xl border-t border-slate-100 bg-slate-50/60 px-6 py-3.5">
                    {{ $footer }}
                </div>
            @endif
        </div>
    </div>
</div>
