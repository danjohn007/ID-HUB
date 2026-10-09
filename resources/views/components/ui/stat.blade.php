{{--
    Tarjeta KPI de color sólido (estilo del mockup del panel principal).
    <x-ui.stat label="Clientes activos" value="842" icon="users" color="brand" trend="+32 este mes" trend-up />
    color: brand | success | danger | warning | purple | teal | navy | slate.  variant="soft" para versión clara.
--}}
@props(['label', 'value', 'icon' => null, 'color' => 'brand', 'trend' => null, 'trendUp' => null, 'sub' => null, 'href' => null, 'variant' => 'solid'])
@php
    $solid = [
        'brand' => 'bg-brand-600', 'success' => 'bg-success-600', 'danger' => 'bg-danger-600', 'warning' => 'bg-warning-600',
        'purple' => 'bg-purple-600', 'teal' => 'bg-teal-600', 'navy' => 'bg-navy-800', 'slate' => 'bg-slate-500', 'info' => 'bg-brand-600',
    ][$color] ?? 'bg-brand-600';
    $softIcon = [
        'brand' => 'bg-brand-50 text-brand-600', 'success' => 'bg-success-50 text-success-600', 'danger' => 'bg-danger-50 text-danger-600',
        'warning' => 'bg-warning-50 text-warning-600', 'purple' => 'bg-purple-50 text-purple-600', 'teal' => 'bg-teal-50 text-teal-600',
        'navy' => 'bg-navy-50 text-navy-800', 'slate' => 'bg-slate-100 text-slate-600', 'info' => 'bg-brand-50 text-brand-600',
    ][$color] ?? 'bg-brand-50 text-brand-600';
    $tag = $href ? 'a' : 'div';
@endphp
@if ($variant === 'solid')
    <{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->merge(['class' => "relative flex items-start gap-4 overflow-hidden rounded-xl p-4 text-white shadow-sm $solid ".($href ? 'transition hover:brightness-105' : '')]) }}>
        @if ($icon)
            <x-dynamic-component :component="'heroicon-s-'.$icon" class="mt-1 h-9 w-9 shrink-0 opacity-95" />
        @endif
        <div class="min-w-0">
            <div class="truncate text-[13px] font-medium text-white/90">{{ $label }}</div>
            <div class="mt-0.5 text-[26px] leading-tight font-bold tracking-tight">{{ $value }}</div>
            @if ($trend)
                <div class="mt-1 flex items-center gap-1 text-xs font-medium text-white/90">
                    @if ($trendUp === true)<x-heroicon-m-arrow-up class="h-3.5 w-3.5" />@elseif ($trendUp === false)<x-heroicon-m-arrow-down class="h-3.5 w-3.5" />@endif
                    {{ $trend }}
                </div>
            @elseif ($sub)
                <div class="mt-1 text-xs text-white/85">{{ $sub }}</div>
            @endif
        </div>
    </{{ $tag }}>
@else
    <{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->merge(['class' => 'card flex items-start gap-3.5 p-4 '.($href ? 'transition hover:border-brand-200' : '')]) }}>
        @if ($icon)
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $softIcon }}">
                <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-6 w-6" />
            </span>
        @endif
        <div class="min-w-0">
            <div class="truncate text-xs font-medium text-slate-500">{{ $label }}</div>
            <div class="mt-0.5 text-xl font-bold text-navy-900">{{ $value }}</div>
            @if ($trend)
                <div @class(['mt-0.5 flex items-center gap-1 text-xs font-medium', 'text-success-600' => $trendUp === true, 'text-danger-600' => $trendUp === false, 'text-slate-500' => $trendUp === null])>
                    @if ($trendUp === true)<x-heroicon-m-arrow-up class="h-3.5 w-3.5" />@elseif ($trendUp === false)<x-heroicon-m-arrow-down class="h-3.5 w-3.5" />@endif
                    {{ $trend }}
                </div>
            @elseif ($sub)
                <div class="mt-0.5 text-xs text-slate-500">{{ $sub }}</div>
            @endif
        </div>
    </{{ $tag }}>
@endif
