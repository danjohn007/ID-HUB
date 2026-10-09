@props(['dark' => true, 'tagline' => true, 'size' => 'md'])
@php
    $tenant = tenant();
    $whiteLabel = $tenant && $tenant->logo_path && $tenant->hasFeature('white_label');
    $text = $size === 'sm' ? 'text-lg' : ($size === 'lg' ? 'text-3xl' : 'text-[22px]');
    $icon = $size === 'sm' ? 'h-6 w-6' : ($size === 'lg' ? 'h-11 w-11' : 'h-8 w-8');
@endphp
@if ($whiteLabel)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
        <img src="{{ $tenant->logoUrl() }}" alt="{{ $tenant->name }}" class="max-h-10 max-w-[170px] object-contain">
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2 select-none']) }}>
        {{-- Ícono Wi-Fi de la marca --}}
        <svg class="{{ $icon }} shrink-0" viewBox="0 0 48 40" fill="none" aria-hidden="true">
            <path d="M4 15.5C9.6 9.6 16.5 6.5 24 6.5S38.4 9.6 44 15.5" stroke="#1A7FF5" stroke-width="5" stroke-linecap="round"/>
            <path d="M11 22.5c3.6-3.7 8-5.6 13-5.6s9.4 1.9 13 5.6" stroke="#1A7FF5" stroke-width="5" stroke-linecap="round"/>
            <path d="M18 29.5c1.7-1.7 3.7-2.6 6-2.6s4.3.9 6 2.6" stroke="{{ $dark ? '#FFFFFF' : '#0B1B3A' }}" stroke-width="5" stroke-linecap="round"/>
            <circle cx="24" cy="35.5" r="3.2" fill="{{ $dark ? '#FFFFFF' : '#0B1B3A' }}"/>
        </svg>
        <div class="leading-none">
            <div class="{{ $text }} font-extrabold tracking-tight">
                <span class="{{ $dark ? 'text-white' : 'text-navy-900' }}">WISP</span><span class="text-brand-500">CONTROL</span>
            </div>
            @if ($tagline)
                <div class="mt-0.5 text-right text-[10px] font-medium {{ $dark ? 'text-navy-200' : 'text-slate-500' }}">{{ config('wisp.brand.tagline') }}</div>
            @endif
        </div>
    </div>
@endif
