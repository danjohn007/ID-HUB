<div class="flex min-h-screen bg-white">
    {{-- ============================================================ Panel de marca (escritorio) --}}
    <aside class="relative hidden overflow-hidden bg-navy-950 lg:flex lg:w-[54%] lg:flex-col lg:p-10 xl:p-14 short:py-7!">
        {{-- Fondo: brillos de marca + retícula + ondas de señal --}}
        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
            <div class="absolute -top-40 -right-24 h-[34rem] w-[34rem] rounded-full bg-brand-600/30 blur-3xl"></div>
            <div class="absolute -bottom-48 -left-32 h-[32rem] w-[32rem] rounded-full bg-teal-600/20 blur-3xl"></div>
            <div class="absolute inset-0 opacity-60"
                 style="background-image: linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px); background-size: 44px 44px; mask-image: radial-gradient(ellipse at 55% 38%, #000 25%, transparent 72%);"></div>
            <div class="absolute top-[38%] left-[55%] -translate-x-1/2 -translate-y-1/2">
                @foreach (['0s', '1.2s', '2.4s'] as $delay)
                    <span class="absolute -top-[280px] -left-[280px] h-[560px] w-[560px] rounded-full border border-brand-400/30 motion-safe:animate-signal" style="animation-delay: {{ $delay }}"></span>
                @endforeach
            </div>
        </div>

        <x-brand.logo size="lg" class="relative" />

        {{-- Maqueta del producto en vivo --}}
        <div class="relative flex flex-1 items-center justify-center py-12 short:py-6">
            <div class="relative w-full max-w-[410px] short:scale-[0.84]">
                {{-- Tarjeta principal: resumen del día --}}
                <div class="relative z-10 rounded-2xl bg-white/95 p-5 shadow-2xl shadow-black/40 ring-1 ring-white/20 backdrop-blur motion-safe:animate-fade-up">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold tracking-[0.14em] text-slate-500 uppercase">Resumen de hoy</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2 py-0.5 text-[11px] font-semibold text-success-700">
                            <span class="relative flex h-1.5 w-1.5"><span class="absolute inline-flex h-full w-full rounded-full bg-success-600 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-success-600"></span></span>
                            En vivo
                        </span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2.5">
                        @foreach ([['Clientes activos', '842', '+32 este mes'], ['MRR', '$294.7k', '+4.1 %']] as [$k, $v, $s])
                            <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100">
                                <div class="text-[10.5px] font-medium text-slate-500">{{ $k }}</div>
                                <div class="mt-1 text-lg leading-none font-extrabold tracking-tight text-navy-900">{{ $v }}</div>
                                <div class="mt-1 text-[10.5px] font-semibold text-success-600">{{ $s }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 rounded-xl bg-navy-900 p-3.5">
                        <div class="flex items-baseline justify-between">
                            <span class="text-[11px] font-medium text-navy-200">Cobranza del mes</span>
                            <span class="text-sm font-bold text-white">$287,450</span>
                        </div>
                        <div class="mt-3 flex h-14 items-end gap-1.5">
                            @foreach ([38, 52, 45, 61, 57, 70, 66, 78, 74, 85, 81, 94] as $i => $h)
                                <span class="flex-1 rounded-sm {{ $i === 11 ? 'bg-brand-400' : 'bg-brand-600/60' }}" style="height: {{ $h }}%"></span>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Notificación: pago y reconexión automática --}}
                <div class="absolute -top-9 -left-14 z-20 w-60 -rotate-3 xl:-left-20">
                    <div class="flex items-start gap-3 rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-success-600 text-white"><x-heroicon-s-check class="h-5 w-5" /></span>
                        <div class="min-w-0 leading-tight">
                            <div class="text-[13px] font-bold text-navy-900">Pago recibido · $450</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">Juan Pérez · servicio reconectado en MikroTik</div>
                        </div>
                    </div>
                </div>

                {{-- Capacidad de repetidor --}}
                <div class="absolute top-14 -right-10 z-20 w-52 rotate-2 xl:-right-20">
                    <div class="rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float" style="animation-delay: -2.3s">
                        <div class="flex items-center justify-between text-[12px] font-bold text-navy-900">
                            <span class="flex items-center gap-1.5"><x-heroicon-o-wifi class="h-4 w-4 text-brand-600" />Repetidor R-07</span>
                            <span class="text-warning-600">87 %</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full w-[87%] rounded-full bg-warning-600"></div></div>
                        <div class="mt-1.5 text-[10.5px] text-slate-500">87 / 100 clientes · sugerir balanceo</div>
                    </div>
                </div>

                {{-- Ticket en campo --}}
                <div class="absolute -bottom-9 -right-6 z-20 w-56 -rotate-1 xl:-right-10">
                    <div class="flex items-center gap-3 rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float" style="animation-delay: -4.6s">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-heroicon-o-truck class="h-5 w-5" /></span>
                        <div class="leading-tight">
                            <div class="text-[13px] font-bold text-navy-900">Ticket #1258</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">Técnico en camino · 12 min</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Propuesta de valor --}}
        <div class="relative max-w-xl">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/15">
                <x-heroicon-o-shield-check class="h-4 w-4 text-brand-300" /> Acceso seguro
            </span>
            <h1 class="mt-4 text-4xl leading-[1.08] font-extrabold tracking-tight text-white xl:text-[44px] short:text-[32px]!">
                Cobra a tiempo. Corta y reconecta solo.
                <span class="bg-gradient-to-r from-brand-300 to-teal-300 bg-clip-text text-transparent">Haz crecer tu WISP.</span>
            </h1>
            <ul class="mt-6 space-y-3 short:mt-4 short:space-y-2">
                @foreach ([['bolt', 'Cortes y reconexiones automáticas en tus MikroTik.'], ['chat-bubble-left-right', 'Recordatorios por WhatsApp con link de pago.'], ['map', 'Mapa de red, técnicos y tickets en un solo lugar.']] as [$icon, $text])
                    <li class="flex items-center gap-3 text-[15px] text-navy-100">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-[18px] w-[18px] text-brand-300" />
                        </span>
                        {{ $text }}
                    </li>
                @endforeach
            </ul>
        </div>
    </aside>

    {{-- ============================================================ Formulario --}}
    <main class="relative flex flex-1 flex-col overflow-hidden">
        <div class="pointer-events-none absolute inset-0" aria-hidden="true"
             style="background: radial-gradient(60rem 40rem at 100% 0%, var(--color-brand-50), transparent 60%), radial-gradient(40rem 30rem at 0% 100%, #f1f5fb, transparent 60%);"></div>

        <div class="relative flex flex-1 items-center justify-center px-4 py-8 sm:px-8">
            <div class="w-full max-w-md motion-safe:animate-fade-up">
                {{-- Encabezado móvil --}}
                <div class="relative mb-8 overflow-hidden rounded-2xl bg-navy-950 p-5 lg:hidden">
                    <div class="pointer-events-none absolute -top-16 -right-10 h-48 w-48 rounded-full bg-brand-600/40 blur-3xl"></div>
                    <x-brand.logo class="relative" />
                    <p class="relative mt-4 text-lg leading-snug font-bold text-white">Cobra a tiempo, corta y reconecta solo. <span class="text-brand-300">Haz crecer tu WISP.</span></p>
                </div>

                <p class="text-xs font-bold tracking-[0.2em] text-brand-600 uppercase">Bienvenido</p>
                <h2 class="mt-2 text-4xl font-extrabold tracking-tight text-navy-900">Inicia sesión</h2>
                <p class="mt-2 text-[15px] text-slate-500">Entra a tu panel de cobranza, red y operación de campo.</p>

                <form wire:submit="login" class="mt-8 space-y-5"
                      x-data="{
                          show: false, forgot: false, lock: 0, timer: null,
                          startLock(seconds) {
                              this.lock = Math.max(1, parseInt(seconds) || 0);
                              clearInterval(this.timer);
                              this.timer = setInterval(() => { if (--this.lock <= 0) { this.lock = 0; clearInterval(this.timer); } }, 1000);
                          },
                      }"
                      x-on:login-locked.window="startLock($event.detail.seconds)">
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-semibold text-navy-900">Correo electrónico</label>
                        <div class="relative">
                            <x-heroicon-o-envelope class="pointer-events-none absolute top-1/2 left-3.5 h-5 w-5 -translate-y-1/2 text-slate-400" />
                            <input id="email" type="email" wire:model="email" autocomplete="username" autofocus required placeholder="tu@empresa.com"
                                   @class(['form-input h-12 rounded-xl pl-11 text-[15px]', 'border-danger-500 focus:border-danger-500 focus:ring-danger-100' => $errors->has('email')])>
                        </div>
                        @error('email')<p x-show="lock === 0" class="mt-1.5 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                        <p x-show="lock > 0" x-cloak class="mt-1.5 text-xs font-medium text-danger-600">
                            Demasiados intentos. Intenta de nuevo en <span class="tabular-nums" x-text="lock"></span> <span x-text="lock === 1 ? 'segundo' : 'segundos'"></span>.
                        </p>
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-semibold text-navy-900">Contraseña</label>
                        <div class="relative">
                            <x-heroicon-o-lock-closed class="pointer-events-none absolute top-1/2 left-3.5 h-5 w-5 -translate-y-1/2 text-slate-400" />
                            <input id="password" x-bind:type="show ? 'text' : 'password'" type="password" wire:model="password" autocomplete="current-password" required placeholder="••••••••"
                                   @class(['form-input h-12 rounded-xl pr-12 pl-11 text-[15px]', 'border-danger-500 focus:border-danger-500 focus:ring-danger-100' => $errors->has('password')])>
                            <button type="button" x-on:click="show = !show" class="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                                    x-bind:aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'" aria-label="Mostrar contraseña">
                                <x-heroicon-o-eye class="h-5 w-5" x-show="!show" />
                                <x-heroicon-o-eye-slash class="h-5 w-5" x-show="show" x-cloak />
                            </button>
                        </div>
                        @error('password')<p class="mt-1.5 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" wire:model="remember" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"> Recordarme
                        </label>
                        <button type="button" x-on:click="forgot = !forgot" class="text-sm font-semibold text-brand-600 hover:text-brand-700 hover:underline">¿Olvidaste tu contraseña?</button>
                    </div>
                    <div x-show="forgot" x-cloak x-transition class="flex gap-2.5 rounded-xl bg-brand-50 p-3 text-sm text-brand-800 ring-1 ring-brand-100">
                        <x-heroicon-o-information-circle class="h-5 w-5 shrink-0 text-brand-600" />
                        <span>Pide al administrador de tu WISP que la restablezca en <span class="font-semibold">Configuración → Usuarios</span>.</span>
                    </div>

                    <button type="submit" wire:loading.attr="disabled" wire:target="login" x-bind:disabled="lock > 0"
                            class="group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 text-[15px] font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700 hover:shadow-brand-700/30 focus:ring-4 focus:ring-brand-200 focus:outline-none disabled:opacity-70">
                        <span wire:loading.remove wire:target="login" class="flex items-center gap-2">Entrar <x-heroicon-m-arrow-right class="h-5 w-5 transition group-hover:translate-x-0.5" /></span>
                        <span wire:loading wire:target="login" class="flex items-center gap-2">
                            <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            Verificando…
                        </span>
                    </button>
                </form>

                @if ($salesUrl = config('wisp.brand.sales_url'))
                    <a href="{{ $salesUrl }}" target="_blank" rel="noopener" class="group mt-6 flex items-center gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 transition hover:ring-brand-200">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-heroicon-o-rocket-launch class="h-5 w-5" /></span>
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block text-sm font-bold text-navy-900">¿Tienes un WISP y aún no usas {{ config('wisp.brand.name') }}?</span>
                            <span class="mt-0.5 block text-sm font-semibold text-brand-600">Agenda una demo gratuita</span>
                        </span>
                        <x-heroicon-m-arrow-right class="h-5 w-5 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-brand-600" />
                    </a>
                @endif

                <div class="mt-8">
                    <p class="text-center text-[11px] font-semibold tracking-[0.16em] text-slate-400 uppercase">Integrado con</p>
                    <div class="mt-3 flex flex-wrap justify-center gap-2">
                        @foreach (['MikroTik', 'WhatsApp', 'Mercado Pago', 'Stripe', 'OXXO Pay', 'PayPal'] as $name)
                            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ $name }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <footer class="relative flex items-center justify-center gap-1.5 pb-6 text-xs text-slate-400">
            <x-heroicon-o-lock-closed class="h-3.5 w-3.5" /> Conexión cifrada · © {{ date('Y') }} {{ config('wisp.brand.name') }}
        </footer>
    </main>
</div>
