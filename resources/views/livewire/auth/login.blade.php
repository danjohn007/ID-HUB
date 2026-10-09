<div class="flex min-h-screen">
    {{-- Panel de marca --}}
    <div class="relative hidden w-1/2 overflow-hidden bg-navy-900 lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div class="pointer-events-none absolute -top-32 -right-32 h-[28rem] w-[28rem] rounded-full bg-brand-600/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-40 -left-20 h-[30rem] w-[30rem] rounded-full bg-teal-600/15 blur-3xl"></div>
        <x-brand.logo size="lg" class="relative" />
        <div class="relative max-w-lg">
            <h1 class="text-4xl leading-tight font-extrabold text-white">Opera tu red WISP de forma <span class="text-brand-400">automatizada, medible y escalable.</span></h1>
            <p class="mt-4 text-navy-200">Cobranza, red MikroTik, mapa y operación de campo en una sola plataforma, alimentada por una misma base de datos operativa.</p>
            <div class="mt-8 grid grid-cols-2 gap-3 text-sm">
                @foreach ([['banknotes', 'Dinero', 'Cobranza y pagos'], ['signal', 'Red', 'MikroTik y repetidores'], ['truck', 'Campo', 'Técnicos y rutas'], ['chart-bar', 'Crecimiento', 'BI y expansión']] as [$icon, $t, $d])
                    <div class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-3">
                        <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-6 w-6 text-brand-400" />
                        <div><div class="font-semibold text-white">{{ $t }}</div><div class="text-xs text-navy-300">{{ $d }}</div></div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="relative text-xs text-navy-400">© {{ date('Y') }} {{ config('wisp.brand.name') }}</div>
    </div>

    {{-- Formulario --}}
    <div class="flex w-full items-center justify-center p-6 lg:w-1/2">
        <div class="w-full max-w-sm">
            <div class="mb-8 lg:hidden"><x-brand.logo :dark="false" /></div>
            <h2 class="text-2xl font-bold text-navy-900">Bienvenido</h2>
            <p class="mt-1 text-sm text-slate-500">Ingresa con tu cuenta para continuar.</p>

            <form wire:submit="login" class="mt-8 space-y-4">
                <x-ui.input label="Correo electrónico" type="email" wire:model="email" autocomplete="username" autofocus required />
                <x-ui.input label="Contraseña" type="password" wire:model="password" autocomplete="current-password" required />
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200"> Recordarme
                </label>
                <x-ui.button type="submit" class="w-full py-2.5" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="login">Iniciar sesión</span>
                    <span wire:loading wire:target="login">Verificando...</span>
                </x-ui.button>
            </form>
        </div>
    </div>
</div>
