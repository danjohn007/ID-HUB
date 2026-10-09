{{-- Foto de evidencia + GPS para formularios de la app del cobrador. Vars: $model, $file, $latField, $lngField, $lat --}}
<div class="grid grid-cols-2 gap-2">
    <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 px-2 py-3 text-center text-xs font-semibold text-slate-600 hover:bg-slate-50">
        <input type="file" accept="image/*" capture="environment" wire:model="{{ $model }}" class="sr-only">
        @if ($file && method_exists($file, 'temporaryUrl'))
            <img src="{{ $file->temporaryUrl() }}" class="h-12 w-12 rounded object-cover" alt="">
            <span class="text-success-700">Foto lista</span>
        @else
            <x-heroicon-o-camera class="h-5 w-5" />
            <span wire:loading.remove wire:target="{{ $model }}">Foto de evidencia</span>
            <span wire:loading wire:target="{{ $model }}">Subiendo…</span>
        @endif
    </label>
    <div x-data="geo('{{ $latField }}', '{{ $lngField }}')" @locate-form.window="locate()"
         class="flex flex-col items-center justify-center gap-1 rounded-lg border border-slate-200 px-2 py-3 text-center text-xs">
        <x-heroicon-o-map-pin class="h-5 w-5 {{ $lat ? 'text-success-600' : 'text-slate-400' }}" />
        <span x-show="locating" class="text-slate-500">Obteniendo GPS…</span>
        <span x-show="!locating" class="font-semibold {{ $lat ? 'text-success-700' : 'text-slate-500' }}">{{ $lat ? 'GPS capturado' : 'Sin GPS' }}</span>
        <button type="button" @click="locate()" class="text-[11px] font-semibold text-brand-600">Actualizar</button>
        <span x-show="error" x-text="error" class="text-[10px] text-danger-600"></span>
    </div>
</div>
@error($model)<p class="text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
