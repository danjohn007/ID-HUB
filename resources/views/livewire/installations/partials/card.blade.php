{{-- Tarjeta compacta de orden (agenda) --}}
<a href="{{ route('installations.show', $o) }}" wire:navigate wire:key="card-{{ $o->id }}"
   class="block rounded-lg border border-slate-200 bg-white p-3 shadow-xs transition hover:border-brand-300 hover:shadow-sm">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold text-slate-800">{{ $o->customerName() }}</div>
            <div class="truncate text-xs text-slate-500">{{ $o->client?->address ?? $o->prospect?->address ?? 'Sin dirección' }}</div>
        </div>
        <x-ui.status catalog="installation_status" :value="$o->status" size="xs" />
    </div>
    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-slate-500">
        <span class="font-semibold text-navy-900">{{ $o->folio }}</span>
        @if ($o->scheduled_at)<span class="inline-flex items-center gap-1"><x-heroicon-m-clock class="h-3.5 w-3.5" />{{ $o->scheduled_at->format('H:i') }}</span>@endif
        <span class="inline-flex items-center gap-1"><x-heroicon-m-user class="h-3.5 w-3.5" />{{ $o->technician?->name ?? 'Sin técnico' }}</span>
        @if ($o->plan)<span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background: {{ $o->plan->color }}"></span>{{ $o->plan->name }}</span>@endif
    </div>
    @if (in_array($o->status, ['en_proceso', 'instalada']))
        <x-ui.progress :value="$o->checklistProgress()" color="teal" height="h-1.5" class="mt-2" />
    @endif
</a>
