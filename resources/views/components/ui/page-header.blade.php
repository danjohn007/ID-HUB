{{-- <x-ui.page-header title="Clientes" subtitle="..." :breadcrumbs="['Clientes' => route('clients.index'), 'Detalle' => null]"> acciones </x-ui.page-header> --}}
@props(['title', 'subtitle' => null, 'breadcrumbs' => []])
<div {{ $attributes->merge(['class' => 'mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($breadcrumbs)
            <nav class="mb-1 flex items-center gap-1 text-xs text-slate-500">
                @foreach ($breadcrumbs as $label => $url)
                    @if ($url)<a href="{{ $url }}" wire:navigate class="hover:text-brand-600">{{ $label }}</a>@else<span class="text-slate-700">{{ $label }}</span>@endif
                    @if (! $loop->last)<x-heroicon-m-chevron-right class="h-3 w-3" />@endif
                @endforeach
            </nav>
        @endif
        <h1 class="truncate text-2xl font-bold tracking-tight text-navy-900">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>@endif
    </div>
    @if (trim($slot))
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
