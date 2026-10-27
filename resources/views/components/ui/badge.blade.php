{{-- <x-ui.badge color="success" dot>Activo</x-ui.badge>  colores: brand|info|success|danger|warning|purple|teal|slate|navy --}}
@props(['color' => 'slate', 'dot' => false, 'size' => 'sm'])
@php
    $classes = [
        'brand' => 'bg-brand-50 text-brand-700 ring-brand-200/60', 'info' => 'bg-brand-50 text-brand-700 ring-brand-200/60',
        'success' => 'bg-success-50 text-success-700 ring-success-100', 'danger' => 'bg-danger-50 text-danger-700 ring-danger-100',
        'warning' => 'bg-warning-50 text-warning-700 ring-warning-100', 'purple' => 'bg-purple-50 text-purple-700 ring-purple-100',
        'teal' => 'bg-teal-50 text-teal-700 ring-teal-100', 'slate' => 'bg-slate-100 text-slate-600 ring-slate-200',
        'navy' => 'bg-navy-900 text-white ring-navy-900',
    ][$color] ?? 'bg-slate-100 text-slate-600 ring-slate-200';
    $dots = [
        'brand' => 'bg-brand-500', 'info' => 'bg-brand-500', 'success' => 'bg-success-600', 'danger' => 'bg-danger-600', 'warning' => 'bg-warning-600',
        'purple' => 'bg-purple-600', 'teal' => 'bg-teal-600', 'slate' => 'bg-slate-400', 'navy' => 'bg-white',
    ][$color] ?? 'bg-slate-400';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full font-semibold ring-1 ring-inset '.$classes.($size === 'xs' ? ' px-1.5 py-0 text-2xs' : ' px-2 py-0.5 text-xs')]) }}>
    @if ($dot)<span class="h-1.5 w-1.5 rounded-full {{ $dots }}"></span>@endif
    {{ $slot }}
</span>
