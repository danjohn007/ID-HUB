{{-- Foto de evidencia + GPS para formularios de la app del cobrador. Vars: $model, $file, $latField, $lngField, $lat --}}
<div class="grid grid-cols-2 gap-2">
    {{-- Evidencia: tomar foto con la cámara o subir un archivo (imagen o PDF, p. ej. comprobante de transferencia) --}}
    <div class="col-span-2">
        @if ($file && method_exists($file, 'temporaryUrl'))
            <div class="flex items-center gap-3 rounded-lg border border-success-200 bg-success-50 px-3 py-2 text-xs">
                @if (str_starts_with((string) $file->getMimeType(), 'image/'))
                    <img src="{{ $file->temporaryUrl() }}" class="h-12 w-12 rounded object-cover" alt="">
                @else
                    <x-heroicon-o-document class="h-10 w-10 text-success-600" />
                @endif
                <div class="min-w-0 flex-1">
                    <div class="font-semibold text-success-700">Evidencia lista</div>
                    <div class="truncate text-slate-500">{{ $file->getClientOriginalName() }}</div>
                </div>
                <button type="button" wire:click="$set('{{ $model }}', null)" class="font-semibold text-slate-500 hover:text-danger-600">Quitar</button>
            </div>
        @else
            <div class="grid grid-cols-2 gap-2">
                <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg bg-brand-600 px-2 py-3 text-center text-xs font-semibold text-white active:scale-[.98]">
                    <input type="file" accept="image/*" capture="environment" wire:model="{{ $model }}" class="sr-only">
                    <x-heroicon-o-camera class="h-5 w-5" /> Tomar foto
                </label>
                <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 px-2 py-3 text-center text-xs font-semibold text-slate-600 hover:bg-slate-50">
                    <input type="file" accept="image/*,application/pdf" wire:model="{{ $model }}" class="sr-only">
                    <x-heroicon-o-arrow-up-tray class="h-5 w-5" /> Subir archivo
                </label>
            </div>
            <div wire:loading wire:target="{{ $model }}" class="mt-1 text-xs font-medium text-brand-700">Subiendo evidencia…</div>
        @endif
    </div>
    <div x-data="geo('{{ $latField }}', '{{ $lngField }}')" @locate-form.window="locate()"
         class="col-span-2 flex flex-col items-center justify-center gap-1 rounded-lg border border-slate-200 px-2 py-3 text-center text-xs">
        <x-heroicon-o-map-pin class="h-5 w-5 {{ $lat ? 'text-success-600' : 'text-slate-400' }}" />
        <span x-show="locating" class="text-slate-500">Obteniendo GPS…</span>
        <span x-show="!locating" class="font-semibold {{ $lat ? 'text-success-700' : 'text-slate-500' }}">{{ $lat ? 'GPS capturado' : 'Sin GPS' }}</span>
        <button type="button" @click="locate()" class="text-[11px] font-semibold text-brand-600">Actualizar</button>
        <span x-show="error" x-text="error" class="text-[10px] text-danger-600"></span>
    </div>
</div>
@error($model)<p class="text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
