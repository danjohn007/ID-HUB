<div class="relative" x-data="{ open: false }" @click.outside="open = false">
    <button type="button" @if ($isSuperadmin) @click="open = !open" @endif
            class="flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm shadow-xs {{ $isSuperadmin ? 'hover:bg-slate-50' : 'cursor-default' }}">
        <x-heroicon-o-building-office-2 class="h-5 w-5 text-slate-500" />
        <span class="hidden text-slate-500 sm:inline">WISP:</span>
        <span class="max-w-[180px] truncate font-semibold text-navy-900">{{ $current?->name ?? 'Plataforma SaaS' }}</span>
        @if ($isSuperadmin)<x-heroicon-m-chevron-down class="h-4 w-4 text-slate-400" />@endif
    </button>
    @if ($isSuperadmin)
        <div x-cloak x-show="open" x-transition class="absolute left-0 z-40 mt-2 max-h-96 w-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
            <button wire:click="switchTo(null)" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-brand-700 hover:bg-brand-50">
                <x-heroicon-o-globe-americas class="h-4 w-4" /> Panel SaaS (todas las empresas)
            </button>
            <div class="my-1 border-t border-slate-100"></div>
            @foreach ($tenants as $t)
                <button wire:click="switchTo({{ $t->id }})" class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50 {{ $current?->id === $t->id ? 'bg-slate-50 font-semibold' : '' }}">
                    <span class="truncate">{{ $t->name }}</span>
                    @if ($t->status !== 'active')<x-ui.badge :color="$t->status === 'suspended' ? 'danger' : 'warning'" size="xs">{{ $t->status }}</x-ui.badge>@endif
                </button>
            @endforeach
        </div>
    @endif
</div>
