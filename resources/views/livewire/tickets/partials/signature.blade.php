{{-- Firma de conformidad del cliente. Variables: $t, $canWork --}}
@if ($t->signature_path)
    <div class="rounded-xl border border-slate-200 bg-slate-50/60 p-3 text-center">
        <img src="{{ \App\Support\Evidence::url($t->signature_path) }}?v={{ $t->signed_at?->timestamp }}" alt="Firma" class="mx-auto h-24 object-contain">
        <div class="mt-1 border-t border-slate-200 pt-1 text-xs text-slate-600">{{ $t->signed_by_name }}</div>
        <div class="text-2xs text-slate-400">{{ $t->signed_at?->format('d/m/Y H:i') }}</div>
    </div>
@elseif (! $canWork)
    <p class="text-sm text-slate-500">Sin firma del cliente.</p>
@endif

@if ($canWork)
    <div class="mt-3" x-data="{ sign: false }" x-on:signature-saved.window="sign = false">
        <x-ui.button x-show="!sign" @click="sign = true" variant="secondary" icon="pencil" class="w-full">{{ $t->signature_path ? 'Capturar nueva firma' : 'Capturar firma del cliente' }}</x-ui.button>
        <div x-show="sign" x-cloak class="space-y-2">
            <x-ui.input label="Nombre de quien firma" wire:model="signedBy" filter="name" maxlength="120" />
            <div wire:ignore>
                <template x-if="sign">
                    <div x-data="signaturePad('signature')">
                        <canvas x-ref="pad" class="h-44 w-full touch-none rounded-xl border-2 border-dashed border-slate-300 bg-white"></canvas>
                        <div class="mt-1 flex items-center justify-between text-2xs text-slate-400">
                            <span x-show="empty">Firme dentro del recuadro</span><span x-show="!empty">Firma capturada</span>
                            <button type="button" @click="clear()" class="font-semibold text-slate-500 hover:text-danger-600">Limpiar</button>
                        </div>
                    </div>
                </template>
            </div>
            @error('signature')<p class="text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
            <div class="grid grid-cols-2 gap-2">
                <x-ui.button variant="secondary" @click="sign = false">Cancelar</x-ui.button>
                <x-ui.button variant="success" icon="check" wire:click="saveSignature">Guardar firma</x-ui.button>
            </div>
        </div>
    </div>
@endif
