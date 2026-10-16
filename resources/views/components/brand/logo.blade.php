@props(['dark' => true, 'tagline' => true, 'size' => 'md'])
@php
    $tenant = tenant();
    $whiteLabel = $tenant && $tenant->logo_path && $tenant->hasFeature('white_label');
    $height = $size === 'sm' ? 'h-9' : ($size === 'lg' ? 'h-16' : 'h-10');
@endphp
@if ($whiteLabel)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
        <img src="{{ $tenant->logoUrl() }}" alt="{{ $tenant->name }}" class="max-h-10 max-w-[170px] object-contain">
    </div>
@else
    <div {{ $attributes->merge(['class' => 'inline-flex w-fit flex-col select-none']) }}>
        {{-- Logo ID-Hub: variante para fondo oscuro ("HUB" en blanco) o claro ("HUB" en navy) --}}
        <img src="{{ asset($dark ? 'images/brand/idhub-logo-dark.png' : 'images/brand/idhub-logo-light.png') }}"
             alt="{{ config('wisp.brand.name') }}" class="{{ $height }} w-auto max-w-none self-start" draggable="false">
        @if ($tagline)
            <div class="mt-1 text-right text-[10px] leading-none font-medium {{ $dark ? 'text-navy-200' : 'text-slate-500' }}">{{ config('wisp.brand.tagline') }}</div>
        @endif
    </div>
@endif
