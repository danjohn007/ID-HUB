@props(['title' => null, 'subtitle' => null, 'padding' => true, 'actions' => null])
<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || $actions)
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
            <div class="min-w-0">
                @if ($title)<h3 class="truncate text-[15px] font-bold text-navy-900">{{ $title }}</h3>@endif
                @if ($subtitle)<p class="mt-0.5 text-xs text-slate-500">{{ $subtitle }}</p>@endif
            </div>
            @if ($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endif
        </div>
    @endif
    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>
</div>
