{{-- <x-ui.progress :value="87" color="warning" label /> --}}
@props(['value' => 0, 'color' => null, 'label' => false, 'height' => 'h-2'])
@php
    $v = max(0, min(100, (float) $value));
    $color ??= $v >= 95 ? 'danger' : ($v >= 80 ? 'warning' : 'success');
    $bar = ['brand' => 'bg-brand-600', 'info' => 'bg-brand-600', 'success' => 'bg-success-600', 'danger' => 'bg-danger-600', 'warning' => 'bg-warning-600', 'purple' => 'bg-purple-600', 'teal' => 'bg-teal-600', 'slate' => 'bg-slate-400'][$color] ?? 'bg-brand-600';
@endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <div class="w-full overflow-hidden rounded-full bg-slate-100 {{ $height }}">
        <div class="{{ $bar }} {{ $height }} rounded-full transition-all" style="width: {{ $v }}%"></div>
    </div>
    @if ($label)<span class="w-11 shrink-0 text-right text-xs font-semibold text-slate-600">{{ round($value) }}%</span>@endif
</div>
