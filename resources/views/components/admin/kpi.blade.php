{{--
    KPI con explicación (tooltip al pasar el cursor o tocar el ícono de información).
    <x-admin.kpi label="MRR" :value="money($mrr)" icon="currency-dollar" color="purple" tip="Ingreso recurrente mensual..." sub="..." :trend="+3.2" />
    trend: número (%) o null. trend-inverse: true si subir es malo (churn, morosidad).
--}}
@props(['label', 'value', 'icon' => null, 'color' => 'brand', 'tip' => null, 'sub' => null, 'trend' => null, 'trendInverse' => false, 'trendSuffix' => '%'])
@php
    $iconCls = [
        'brand' => 'bg-brand-50 text-brand-600', 'success' => 'bg-success-50 text-success-600', 'danger' => 'bg-danger-50 text-danger-600',
        'warning' => 'bg-warning-50 text-warning-600', 'purple' => 'bg-purple-50 text-purple-600', 'teal' => 'bg-teal-50 text-teal-600',
        'navy' => 'bg-navy-50 text-navy-800', 'slate' => 'bg-slate-100 text-slate-600',
    ][$color] ?? 'bg-brand-50 text-brand-600';
    $good = $trend === null ? null : ($trendInverse ? $trend <= 0 : $trend >= 0);
@endphp
<div {{ $attributes->merge(['class' => 'card relative flex items-start gap-3 p-4']) }} x-data="{ tip: false }">
    @if ($icon)
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $iconCls }}">
            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5" />
        </span>
    @endif
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1 text-xs font-medium text-slate-500">
            <span class="truncate">{{ $label }}</span>
            @if ($tip)
                <button type="button" class="shrink-0 text-slate-400 hover:text-brand-600" @mouseenter="tip = true" @mouseleave="tip = false" @click="tip = !tip" aria-label="¿Qué es {{ $label }}?">
                    <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                </button>
            @endif
        </div>
        <div class="mt-0.5 truncate text-xl font-bold text-navy-900">{{ $value }}</div>
        @if ($trend !== null)
            <div @class(['mt-0.5 flex items-center gap-1 text-xs font-medium', 'text-success-600' => $good, 'text-danger-600' => ! $good])>
                @if ($trend >= 0)<x-heroicon-m-arrow-up class="h-3.5 w-3.5" />@else<x-heroicon-m-arrow-down class="h-3.5 w-3.5" />@endif
                {{ ($trend >= 0 ? '+' : '').$trend.$trendSuffix }} <span class="font-normal text-slate-400">vs. periodo anterior</span>
            </div>
        @endif
        @if ($sub)
            <div class="mt-0.5 truncate text-xs text-slate-500">{{ $sub }}</div>
        @endif
    </div>
    @if ($tip)
        <div x-cloak x-show="tip" x-transition.opacity class="absolute top-full left-3 right-3 z-30 mt-1 rounded-lg bg-navy-900 px-3 py-2 text-xs leading-relaxed text-white shadow-lg">
            {{ $tip }}
        </div>
    @endif
</div>
