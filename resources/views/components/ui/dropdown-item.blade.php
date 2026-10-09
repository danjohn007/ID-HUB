@props(['icon' => null, 'href' => null, 'danger' => false])
@php $classes = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm '.($danger ? 'text-danger-600 hover:bg-danger-50' : 'text-slate-700 hover:bg-slate-50'); @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 opacity-70" />@endif {{ $slot }}
    </a>
@else
    <button type="button" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 opacity-70" />@endif {{ $slot }}
    </button>
@endif
