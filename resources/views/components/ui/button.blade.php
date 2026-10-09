{{--
    <x-ui.button variant="primary" icon="plus" wire:click="create">Nuevo</x-ui.button>
    <x-ui.button href="{{ route('x') }}" variant="secondary">Ver</x-ui.button>
    variant: primary | secondary | danger | success | ghost | white ; size: sm | md
--}}
@props(['variant' => 'primary', 'size' => 'md', 'icon' => null, 'href' => null, 'type' => 'button', 'navigate' => true])
@php
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold transition focus:outline-none focus:ring-3 disabled:cursor-not-allowed disabled:opacity-60 whitespace-nowrap';
    $sizes = ['sm' => 'px-2.5 py-1.5 text-xs', 'md' => 'px-3.5 py-2 text-sm'][$size] ?? 'px-3.5 py-2 text-sm';
    $variants = [
        'primary' => 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 focus:ring-brand-200',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 shadow-xs hover:bg-slate-50 focus:ring-slate-200',
        'danger' => 'bg-danger-600 text-white shadow-sm hover:bg-danger-700 focus:ring-danger-100',
        'success' => 'bg-success-600 text-white shadow-sm hover:bg-success-700 focus:ring-success-100',
        'warning' => 'bg-warning-600 text-white shadow-sm hover:bg-warning-700 focus:ring-warning-100',
        'ghost' => 'text-slate-600 hover:bg-slate-100 focus:ring-slate-200',
        'white' => 'bg-white text-brand-700 shadow-sm hover:bg-brand-50 focus:ring-white/40',
    ][$variant] ?? '';
    $iconSize = $size === 'sm' ? 'h-4 w-4' : 'h-[18px] w-[18px]';
@endphp
@if ($href)
    <a href="{{ $href }}" @if ($navigate) wire:navigate @endif {{ $attributes->merge(['class' => "$base $sizes $variants"]) }}>
        @if ($icon)<x-dynamic-component :component="'heroicon-m-'.$icon" :class="$iconSize" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => "$base $sizes $variants"]) }}>
        @php $target = $attributes->get('wire:click'); @endphp
        @if ($icon)
            <span @if ($target) wire:loading.remove wire:target="{{ $target }}" @endif class="inline-flex"><x-dynamic-component :component="'heroicon-m-'.$icon" :class="$iconSize" /></span>
        @endif
        @if ($target)
            <svg wire:loading wire:target="{{ $target }}" class="{{ $iconSize }} animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
        @endif
        {{ $slot }}
    </button>
@endif
