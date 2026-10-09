@props(['name' => '', 'size' => 'md', 'color' => 'brand'])
@php
    $s = ['sm' => 'h-7 w-7 text-[11px]', 'md' => 'h-9 w-9 text-xs', 'lg' => 'h-12 w-12 text-sm', 'xl' => 'h-16 w-16 text-lg'][$size] ?? 'h-9 w-9 text-xs';
    $c = ['brand' => 'bg-brand-100 text-brand-700', 'navy' => 'bg-navy-900 text-white', 'success' => 'bg-success-100 text-success-700', 'purple' => 'bg-purple-100 text-purple-700'][$color] ?? 'bg-brand-100 text-brand-700';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-full font-bold $s $c"]) }}>{{ initials($name) }}</span>
