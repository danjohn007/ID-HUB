{{-- <x-ui.toggle wire:model.live="form.active" label="Activo" description="..." /> --}}
@props(['label' => null, 'description' => null])
<label {{ $attributes->only('class')->merge(['class' => 'flex cursor-pointer items-start gap-3']) }}>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" {{ $attributes->except('class') }} class="peer sr-only">
        <span class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-brand-600 peer-focus:ring-3 peer-focus:ring-brand-100"></span>
        <span class="absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
    </span>
    @if ($label || $description)
        <span class="leading-tight">
            @if ($label)<span class="block text-sm font-medium text-slate-700">{{ $label }}</span>@endif
            @if ($description)<span class="mt-0.5 block text-xs text-slate-500">{{ $description }}</span>@endif
        </span>
    @endif
</label>
