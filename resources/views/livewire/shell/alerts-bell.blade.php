<div class="relative" x-data="{ open: false }" @click.outside="open = false" wire:poll.60s>
    <button type="button" @click="open = !open" class="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Alertas">
        <x-heroicon-o-bell class="h-6 w-6" />
        @if ($count > 0)
            <span class="absolute top-1 right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-danger-600 px-1 text-[10px] font-bold text-white">{{ $count > 99 ? '99+' : $count }}</span>
        @endif
    </button>
    <div x-cloak x-show="open" x-transition class="absolute right-0 z-40 mt-2 w-[22rem] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <span class="text-sm font-bold text-navy-900">Alertas inteligentes</span>
            @if (Route::has('alerts.index'))<a href="{{ route('alerts.index') }}" wire:navigate class="text-xs font-semibold text-brand-600">Ver todas →</a>@endif
        </div>
        <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto">
            @forelse ($alerts as $a)
                @php $c = ['critical' => 'bg-danger-600', 'warning' => 'bg-warning-600', 'info' => 'bg-brand-600'][$a->severity] ?? 'bg-slate-400'; @endphp
                <div class="flex gap-3 px-4 py-3">
                    <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $c }}"></span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-slate-800">{{ $a->title }}</div>
                        @if ($a->message)<div class="line-clamp-2 text-xs text-slate-500">{{ $a->message }}</div>@endif
                        <div class="mt-1 flex items-center gap-3 text-2xs text-slate-400">
                            {{ $a->created_at->diffForHumans() }}
                            @if ($url = $a->subjectUrl())<a href="{{ $url }}" wire:navigate class="font-semibold text-brand-600">Ver</a>@endif
                            <button wire:click="acknowledge({{ $a->id }})" class="font-semibold text-slate-500 hover:text-slate-700">Enterado</button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-slate-500">Sin alertas pendientes 🎉</div>
            @endforelse
        </div>
    </div>
</div>
