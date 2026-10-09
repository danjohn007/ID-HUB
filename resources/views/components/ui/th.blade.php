@props(['sort' => null, 'sortBy' => null, 'sortDir' => 'asc'])
<th {{ $attributes }}>
    @if ($sort)
        <button type="button" wire:click="sort('{{ $sort }}')" class="inline-flex items-center gap-1 uppercase hover:text-slate-700">
            {{ $slot }}
            @if ($sortBy === $sort)
                @if ($sortDir === 'asc')<x-heroicon-m-chevron-up class="h-3.5 w-3.5" />@else<x-heroicon-m-chevron-down class="h-3.5 w-3.5" />@endif
            @else
                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 opacity-40" />
            @endif
        </button>
    @else
        {{ $slot }}
    @endif
</th>
