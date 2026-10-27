{{-- Evidencias fotográficas con GPS (sección 24). Variables: $attachments, $canWork, $compact (opcional) --}}
@php $kinds = collect(config('wisp.evidence_kinds'))->except(['firma', 'comprobante']); @endphp
@if ($canWork)
    <div class="mb-4 space-y-3">
        <div class="flex gap-2 overflow-x-auto pb-1">
            @foreach ($kinds as $k => $label)
                <button type="button" wire:click="$set('photoKind', '{{ $k }}')" @class(['shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition', 'bg-brand-600 text-white ring-brand-600' => $photoKind === $k, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $photoKind !== $k])>
                    {{ ['antes' => 'Antes', 'durante' => 'Durante', 'despues' => 'Después', 'equipo' => 'Equipo', 'instalacion' => 'Instalación', 'otro' => 'Otro'][$k] ?? $label }}
                </button>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-2" x-data="geo('photoLat', 'photoLng')">
            <label @click="locate()" class="flex cursor-pointer items-center justify-center gap-2 rounded-xl bg-brand-600 px-3 py-3 text-sm font-semibold text-white shadow-sm active:scale-[.98] hover:bg-brand-700">
                <x-heroicon-o-camera class="h-5 w-5" /> Tomar foto
                <input type="file" accept="image/*" capture="environment" wire:model="photo" class="sr-only">
            </label>
            <label @click="locate()" class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50">
                <x-heroicon-o-photo class="h-5 w-5" /> Galería
                <input type="file" accept="image/*,application/pdf" wire:model="photo" class="sr-only">
            </label>
            <p class="col-span-2 flex items-center gap-1.5 text-2xs text-slate-500">
                <x-heroicon-m-map-pin class="h-3.5 w-3.5" />
                <span x-show="locating">Obteniendo ubicación GPS...</span>
                <span x-show="!locating && !error">Se guarda como «{{ $kinds[$photoKind] ?? $photoKind }}» con fecha, hora y GPS{{ $photoLat ? ' ('.round((float) $photoLat, 5).', '.round((float) $photoLng, 5).')' : '' }}.</span>
                <span x-show="error" x-text="'Sin GPS: ' + error" class="text-warning-600"></span>
            </p>
        </div>
        <div wire:loading wire:target="photo" class="flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-xs font-medium text-brand-700">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Subiendo evidencia...
        </div>
        @error('photo')<p class="text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
    </div>
@endif

<div @class(['grid gap-3', 'grid-cols-2 sm:grid-cols-3' => $compact ?? false, 'grid-cols-2 md:grid-cols-4' => ! ($compact ?? false)])>
    @forelse ($attachments as $a)
        <div wire:key="att{{ $a->id }}" class="group relative overflow-hidden rounded-xl border border-slate-200 bg-white">
            <a href="{{ $a->url() }}" target="_blank">
                @if ($a->isImage())
                    <img src="{{ $a->url() }}" loading="lazy" class="h-28 w-full object-cover group-hover:opacity-90" alt="{{ $a->kind }}">
                @else
                    <div class="flex h-28 items-center justify-center bg-slate-50"><x-heroicon-o-document class="h-10 w-10 text-slate-400" /></div>
                @endif
            </a>
            <span class="absolute top-1.5 left-1.5 rounded-md bg-navy-900/80 px-1.5 py-0.5 text-2xs font-semibold text-white">{{ ['antes' => 'Antes', 'durante' => 'Durante', 'despues' => 'Después', 'equipo' => 'Equipo', 'instalacion' => 'Instalación', 'firma' => 'Firma', 'otro' => 'Otro'][$a->kind] ?? $a->kind }}</span>
            <div class="p-2 text-2xs leading-tight">
                <div class="text-slate-600">{{ ($a->taken_at ?? $a->created_at)->format('d/m/Y H:i') }}</div>
                <div class="flex items-center justify-between gap-1">
                    @if ($a->lat)
                        <a href="https://www.google.com/maps?q={{ $a->lat }},{{ $a->lng }}" target="_blank" class="inline-flex items-center gap-0.5 text-brand-600 hover:underline"><x-heroicon-m-map-pin class="h-3 w-3" />GPS</a>
                    @else
                        <span class="text-slate-400">Sin GPS</span>
                    @endif
                    <span class="truncate text-slate-400">{{ $a->uploadedBy?->name }}</span>
                </div>
            </div>
            @if ($canWork && (auth()->user()->can('tickets.manage') || (int) $a->uploaded_by === (int) auth()->id()))
                <button type="button" wire:click="deletePhoto({{ $a->id }})" wire:confirm="¿Eliminar esta evidencia?" class="absolute top-1.5 right-1.5 rounded-md bg-white/90 p-1 text-slate-500 shadow hover:text-danger-600" title="Eliminar">
                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                </button>
            @endif
        </div>
    @empty
        <p class="col-span-full rounded-lg border border-dashed border-slate-300 py-6 text-center text-sm text-slate-500">Sin evidencias fotográficas.</p>
    @endforelse
</div>
