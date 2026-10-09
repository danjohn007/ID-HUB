@props(['icon' => 'inbox', 'title' => 'Sin resultados', 'description' => null, 'colspan' => null])
@php $content = '' @endphp
@if ($colspan)
    <tr><td colspan="{{ $colspan }}">
@endif
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-4 py-10 text-center']) }}>
    <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-6 w-6" />
    </span>
    <p class="text-sm font-semibold text-slate-700">{{ $title }}</p>
    @if ($description)<p class="mt-1 max-w-sm text-xs text-slate-500">{{ $description }}</p>@endif
    @if (trim($slot))<div class="mt-4">{{ $slot }}</div>@endif
</div>
@if ($colspan)
    </td></tr>
@endif
