{{--
    <x-ui.input label="Nombre" wire:model="form.name" name="form.name" required /> — el error se toma de "name" o de wire:model
    filter: bloquea al escribir los caracteres que no corresponden (ver inputFilters en resources/js/app.js y App\Support\InputRules):
        name · text · alnum · digits · decimal · signed · phone · code · upper · username · slug · email · rfc · curp · mac · ip · host · color
    <x-ui.input label="Teléfono" wire:model="form.phone" filter="phone" />   (maxlength e inputmode se ponen solos; se pueden sobrescribir)
--}}
@props(['label' => null, 'name' => null, 'hint' => null, 'prefix' => null, 'suffix' => null, 'type' => 'text', 'filter' => null])
@php
    $field = $name ?? $attributes->whereStartsWith('wire:model')->first();
    // id estable entre renders: Livewire usa el id como llave al actualizar el DOM; si cambiara, el campo se
    // reemplazaría en cada petición (wire:model.live) y el usuario perdería el cursor y lo que iba escribiendo.
    $id = $attributes->get('id') ?? 'f_'.($field ? str_replace(['.', '[', ']'], '_', (string) $field) : substr(md5(uniqid()), 0, 6));
    // Teclado del celular y longitud por omisión según el filtro
    $presets = [
        'name' => ['maxlength' => 80], 'text' => ['maxlength' => 190], 'alnum' => ['maxlength' => 120],
        'digits' => ['inputmode' => 'numeric'], 'decimal' => ['inputmode' => 'decimal'], 'signed' => ['inputmode' => 'decimal', 'maxlength' => 16],
        'phone' => ['inputmode' => 'tel', 'maxlength' => 20], 'code' => ['maxlength' => 30], 'upper' => ['maxlength' => 30],
        'username' => ['maxlength' => 64, 'autocapitalize' => 'off'], 'slug' => ['maxlength' => 60, 'autocapitalize' => 'off'],
        'email' => ['inputmode' => 'email', 'maxlength' => 150, 'autocapitalize' => 'off'], 'rfc' => ['maxlength' => 13], 'curp' => ['maxlength' => 18],
        'mac' => ['maxlength' => 17], 'ip' => ['inputmode' => 'decimal', 'maxlength' => 15], 'host' => ['maxlength' => 190, 'autocapitalize' => 'off'],
        'color' => ['maxlength' => 7],
    ];
    $extra = $filter ? ['data-filter' => $filter] + array_filter($presets[$filter] ?? [], fn ($v, $k) => ! $attributes->has($k), ARRAY_FILTER_USE_BOTH) : [];
@endphp
<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($attributes->has('required'))<span class="text-danger-600">*</span>@endif</label>
    @endif
    <div class="relative flex">
        @if ($prefix)
            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $prefix }}</span>
        @endif
        <input id="{{ $id }}" type="{{ $type }}" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('class')->merge($extra)->merge(['class' => 'form-input'.($prefix ? ' rounded-l-none' : '').($suffix ? ' rounded-r-none' : '').($field && $errors->has($field) ? ' border-danger-500 focus:border-danger-500 focus:ring-danger-100' : '')]) }}>
        @if ($suffix)
            <span class="inline-flex items-center rounded-r-lg border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">{{ $suffix }}</span>
        @endif
    </div>
    @if ($hint)<p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>@endif
    @if ($field)
        @error($field)<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
    @endif
</div>
