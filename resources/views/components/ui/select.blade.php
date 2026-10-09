{{--
    <x-ui.select label="Plan" wire:model="form.plan_id" :options="$plans" placeholder="Selecciona..." />
    options: ['valor' => 'Etiqueta'] o colección id => nombre. También acepta <option> en el slot.
--}}
@props(['label' => null, 'name' => null, 'options' => [], 'placeholder' => null, 'hint' => null])
@php
    $field = $name ?? $attributes->whereStartsWith('wire:model')->first();
    $id = 'f_'.str_replace(['.', '[', ']'], '_', (string) $field).'_'.substr(md5(uniqid()), 0, 4);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($attributes->has('required'))<span class="text-danger-600">*</span>@endif</label>
    @endif
    <select id="{{ $id }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('class')->merge(['class' => 'form-input pr-8'.($field && $errors->has($field) ? ' border-danger-500' : '')]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ is_array($text) ? $text[0] : $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
    @if ($field)
        @error($field)<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
    @endif
</div>
