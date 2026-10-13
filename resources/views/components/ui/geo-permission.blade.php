{{--
    Aviso para activar la ubicación en las pantallas de campo. Se oculta solo cuando el permiso ya está concedido.
    <x-ui.geo-permission />
--}}
<div x-data="geoPermission" x-show="state !== 'granted' && state !== 'checking'" x-cloak
     {{ $attributes->merge(['class' => 'mb-4 rounded-2xl border p-4 shadow-xs']) }}
     :class="state === 'prompt' ? 'border-brand-200 bg-brand-50' : 'border-warning-200 bg-warning-50'">
    <div class="flex items-start gap-3">
        <x-heroicon-o-map-pin class="mt-0.5 h-6 w-6 shrink-0" ::class="state === 'prompt' ? 'text-brand-600' : 'text-warning-600'" />
        <div class="min-w-0 flex-1 text-sm">
            <template x-if="state === 'prompt'">
                <div>
                    <div class="font-semibold text-navy-900">Activa tu ubicación</div>
                    <p class="mt-0.5 text-slate-600">Se usa para registrar llegada, salida, cobros y evidencias con GPS.</p>
                </div>
            </template>
            <template x-if="state === 'denied'">
                <div>
                    <div class="font-semibold text-navy-900">La ubicación está bloqueada</div>
                    <p class="mt-0.5 text-slate-600">Toca el candado junto a la dirección del sitio → <b>Ubicación</b> → <b>Permitir</b>, y recarga la página.</p>
                </div>
            </template>
            <template x-if="state === 'insecure'">
                <div>
                    <div class="font-semibold text-navy-900">Ubicación no disponible</div>
                    <p class="mt-0.5 text-slate-600">El navegador solo comparte el GPS en sitios con <b>HTTPS</b>. Pide al administrador activar el certificado SSL.</p>
                </div>
            </template>
            <template x-if="state === 'unsupported'">
                <div class="font-semibold text-navy-900">Este dispositivo no permite obtener la ubicación.</div>
            </template>
            <p x-show="error && state === 'prompt'" x-text="error" class="mt-1 text-xs font-medium text-warning-700"></p>
        </div>
    </div>
    <template x-if="state === 'prompt' || state === 'denied'">
        <button type="button" @click="request()" :disabled="asking"
                class="mt-3 flex w-full items-center justify-center gap-1.5 rounded-xl bg-brand-600 py-2.5 text-sm font-semibold text-white shadow-sm active:scale-[.98] disabled:opacity-70">
            <x-heroicon-o-map-pin class="h-5 w-5" /> <span x-text="asking ? 'Esperando permiso...' : (state === 'denied' ? 'Volver a intentar' : 'Permitir ubicación')"></span>
        </button>
    </template>
</div>
