<div class="relative mx-auto max-w-xl" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
    <input type="search" wire:model.live.debounce.250ms="q" @focus="open = true" @input="open = true"
           placeholder="Buscar clientes, tickets, equipos..." class="form-input h-10 bg-slate-50 pl-9" autocomplete="off">
    @if (mb_strlen(trim($q)) >= 2)
        <div x-cloak x-show="open" x-transition class="absolute z-40 mt-2 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
            @forelse ($results as $group => $items)
                <div class="bg-slate-50 px-3 py-1.5 text-[11px] font-semibold tracking-wide text-slate-500 uppercase">{{ $group }}</div>
                @foreach ($items as $r)
                    <a href="{{ $r['url'] }}" wire:navigate class="flex items-center gap-3 px-3 py-2 hover:bg-brand-50">
                        <x-dynamic-component :component="'heroicon-o-'.$r['icon']" class="h-4 w-4 shrink-0 text-slate-400" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-slate-800">{{ $r['title'] }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ $r['sub'] }}</span>
                        </span>
                    </a>
                @endforeach
            @empty
                <div class="px-4 py-6 text-center text-sm text-slate-500">Sin resultados para "{{ $q }}"</div>
            @endforelse
        </div>
    @endif
</div>
