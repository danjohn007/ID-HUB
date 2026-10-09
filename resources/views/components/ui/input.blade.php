{{-- <x-ui.input label="Nombre" wire:model="form.name" name="form.name" required /> — el error se toma de "name" o de wire:model --}}
@props(['label' => null, 'name' => null, 'hint' => null, 'prefix' => null, 'suffix' => null, 'type' => 'text'])
@php
    $field = $name ?? $attributes->whereStartsWith('wire:model')->first();
    $id = $attributes->get('id') ?? 'f_'.str_replace(['.', '[', ']'], '_', (string) $field).'_'.substr(md5(uniqid()), 0, 4);
@endphp
<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($attributes->has('required'))<span class="text-danger-600">*</span>@endif</label>
    @endif
    <div class="relative flex">
        @if ($prefix)
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $prefix }}</span>
        @endif
        <input id="{{ $id }}" type="{{ $type }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('class')->merge(['class' => 'form-input'.($prefix ? ' rounded-l-none' : '').($suffix ? ' rounded-r-none' : '').($field && $errors->has($field) ? ' border-danger-500 focus:border-danger-500 focus:ring-danger-100' : '')]) }}>
        @if ($suffix)
            <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $suffix }}</span>
        @endif
    </div>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
    @if ($field)
        @error($field)<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
    @endif
</div>
