@props(['label' => null, 'name' => null, 'hint' => null, 'rows' => 3])
@php $field = $name ?? $attributes->whereStartsWith('wire:model')->first(); @endphp
<div {{ $attributes->only('class') }}>
    @if ($label)<label class="form-label">{{ $label }} @if ($attributes->has('required'))<span class="text-danger-600">*</span>@endif</label>@endif
    <textarea rows="{{ $rows }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('class')->merge(['class' => 'form-input'.($field && $errors->has($field) ? ' border-danger-500' : '')]) }}>{{ $slot }}</textarea>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
    @if ($field)
        @error($field)<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
    @endif
</div>
