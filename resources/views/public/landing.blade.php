@php
    $brand = config('wisp.brand.name', 'WISPCONTROL');
    $salesUrl = config('wisp.brand.sales_url');
    $demoUrl = $salesUrl ?: route('login');
    $demoAttrs = $salesUrl ? 'target="_blank" rel="noopener"' : '';
@endphp
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand }} · Software para WISP: cobranza, MikroTik y técnicos en un solo lugar</title>
    <meta name="description" content="Plataforma SaaS para proveedores de internet inalámbrico (WISP): cobranza automática, cortes y reconexiones en MikroTik, WhatsApp, app para técnicos, cobradores y clientes.">
    <meta property="og:title" content="{{ $brand }} · Tu WISP bajo control">
    <meta property="og:description" content="Cobra a tiempo, corta y reconecta solo, y haz crecer tu red. Todo en una sola plataforma.">
    <meta property="og:type" content="website">
    <meta name="theme-color" content="#071330">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
    <style>
        /* Aparición al hacer scroll (solo si hay JS; sin JS todo queda visible) */
        .js .reveal { opacity: 0; transform: translateY(24px); transition: opacity .8s cubic-bezier(.2,.7,.2,1), transform .8s cubic-bezier(.2,.7,.2,1); }
        .js .reveal.is-visible { opacity: 1; transform: none; }
        @keyframes lp-marquee { to { transform: translateX(-50%); } }
        .lp-marquee { animation: lp-marquee 38s linear infinite; }
        @keyframes lp-dash { to { stroke-dashoffset: -24; } }
        .lp-dash { stroke-dasharray: 6 6; animation: lp-dash 1.2s linear infinite; }
        @keyframes lp-travel { 0% { left: 0; opacity: 0; } 8% { opacity: 1; } 92% { opacity: 1; } 100% { left: 100%; opacity: 0; } }
        .lp-travel { animation: lp-travel 6s cubic-bezier(.45,.05,.55,.95) infinite; }
        @keyframes lp-glow { 0%, 100% { opacity: .55; } 50% { opacity: 1; } }
        .lp-glow { animation: lp-glow 4s ease-in-out infinite; }
        .lp-grid { background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px); background-size: 48px 48px; }
        @media (prefers-reduced-motion: reduce) {
            .lp-marquee, .lp-dash, .lp-travel, .lp-glow { animation: none !important; }
            .js .reveal { opacity: 1; transform: none; transition: none; }
        }
    </style>
    <script>document.documentElement.classList.add('js')</script>
</head>
<body class="bg-white text-slate-700 antialiased">

{{-- ============================================================ Navegación --}}
<header id="top" class="fixed inset-x-0 top-0 z-50 transition-all duration-300" data-nav>
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:h-[72px] lg:px-8">
        <a href="#top" class="shrink-0" aria-label="{{ $brand }}"><x-brand.logo size="sm" /></a>
        <nav class="hidden items-center gap-1 lg:flex" aria-label="Principal">
            @foreach (['#automatizacion' => 'Automatización', '#modulos' => 'Módulos', '#como-funciona' => 'Cómo funciona', '#para-quien' => 'Para quién', '#planes' => 'Planes', '#preguntas' => 'Preguntas'] as $href => $label)
                <a href="{{ $href }}" class="rounded-lg px-3 py-2 text-sm font-medium text-navy-100 transition hover:bg-white/10 hover:text-white">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="flex items-center gap-2">
            <a href="{{ route('login') }}" class="hidden rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-white/10 sm:inline-flex">Iniciar sesión</a>
            <a href="{{ $demoUrl }}" {!! $demoAttrs !!} class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold whitespace-nowrap text-white shadow-lg shadow-brand-900/40 transition hover:bg-brand-500">
                <span class="sm:hidden">Demo</span><span class="hidden sm:inline">Agenda una demo</span> <x-heroicon-m-arrow-right class="hidden h-4 w-4 sm:block" />
            </a>
            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-white hover:bg-white/10 lg:hidden" data-menu-toggle aria-label="Abrir menú" aria-expanded="false">
                <x-heroicon-o-bars-3 class="h-6 w-6" />
            </button>
        </div>
    </div>
    <div class="hidden border-t border-white/10 bg-navy-950/95 backdrop-blur lg:hidden" data-menu>
        <nav class="mx-auto grid max-w-7xl gap-1 px-4 py-3 sm:px-6">
            @foreach (['#automatizacion' => 'Automatización', '#modulos' => 'Módulos', '#como-funciona' => 'Cómo funciona', '#para-quien' => 'Para quién', '#planes' => 'Planes', '#preguntas' => 'Preguntas', route('login') => 'Iniciar sesión'] as $href => $label)
                <a href="{{ $href }}" class="rounded-lg px-3 py-2.5 text-sm font-medium text-navy-100 hover:bg-white/10 hover:text-white" data-menu-link>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>

<main>
{{-- ============================================================ Hero --}}
<section class="relative overflow-hidden bg-navy-950 pt-28 pb-20 sm:pt-32 lg:pt-40 lg:pb-28">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <div class="lp-glow absolute -top-48 right-[-10%] h-[42rem] w-[42rem] rounded-full bg-brand-600/30 blur-3xl"></div>
        <div class="lp-glow absolute bottom-[-30%] left-[-15%] h-[38rem] w-[38rem] rounded-full bg-teal-600/20 blur-3xl" style="animation-delay:-2s"></div>
        <div class="lp-grid absolute inset-0" style="mask-image: radial-gradient(ellipse at 60% 35%, #000 20%, transparent 70%)"></div>
        <div class="absolute top-[42%] left-[72%] hidden -translate-x-1/2 -translate-y-1/2 lg:block">
            @foreach (['0s', '1.2s', '2.4s'] as $delay)
                <span class="absolute -top-[320px] -left-[320px] h-[640px] w-[640px] rounded-full border border-brand-400/25 motion-safe:animate-signal" style="animation-delay: {{ $delay }}"></span>
            @endforeach
        </div>
    </div>

    <div class="relative mx-auto grid max-w-7xl items-center gap-16 px-4 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:px-8">
        <div class="motion-safe:animate-fade-up">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-brand-100 ring-1 ring-white/15">
                <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full rounded-full bg-success-500 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-success-500"></span></span>
                Plataforma SaaS para WISP y redes comunitarias
            </span>
            <h1 class="mt-6 text-[2.6rem] leading-[1.04] font-extrabold tracking-tight text-white sm:text-6xl lg:text-[4.1rem]">
                Tu WISP trabajando
                <span class="block bg-gradient-to-r from-brand-300 via-brand-400 to-teal-300 bg-clip-text text-transparent">en piloto automático.</span>
            </h1>
            <p class="mt-6 max-w-xl text-lg leading-relaxed text-navy-100">
                Cobranza, cortes y reconexiones en MikroTik, técnicos, cobradores y la app de tus clientes en <strong class="font-semibold text-white">una sola plataforma</strong>.
                Deja atrás los cuadernos, las hojas de cálculo y los mensajes de WhatsApp a mano.
            </p>
            <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                <a href="{{ $demoUrl }}" {!! $demoAttrs !!} class="group inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-6 py-3.5 text-base font-bold text-white shadow-xl shadow-brand-600/30 transition hover:-translate-y-0.5 hover:bg-brand-500">
                    Agenda una demo gratuita <x-heroicon-m-arrow-right class="h-5 w-5 transition group-hover:translate-x-1" />
                </a>
                <a href="#automatizacion" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white/5 px-6 py-3.5 text-base font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/10">
                    <x-heroicon-s-play-circle class="h-5 w-5 text-brand-300" /> Ver cómo funciona
                </a>
            </div>
            <div class="mt-10 grid max-w-xl gap-3 sm:grid-cols-2">
                @foreach ([
                    ['bolt', 'Cortes y reconexiones automáticas'],
                    ['chat-bubble-left-right', 'Recordatorios por WhatsApp con link de pago'],
                    ['device-phone-mobile', 'Apps para técnicos, cobradores y clientes'],
                    ['swatch', 'Tu marca, tus colores, tu dominio'],
                ] as [$icon, $text])
                    <div class="flex items-center gap-3 rounded-xl bg-white/[0.04] p-3 ring-1 ring-white/10">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600/20 text-brand-300"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5" /></span>
                        <span class="text-[13px] leading-snug font-medium text-navy-100">{{ $text }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Maqueta del producto --}}
        <div class="relative mx-auto w-full max-w-[560px] motion-safe:animate-fade-up" style="animation-delay:.15s">
            <div class="relative rounded-2xl bg-white/95 shadow-2xl shadow-black/50 ring-1 ring-white/20">
                <div class="flex items-center gap-1.5 border-b border-slate-100 px-4 py-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-danger-500/80"></span><span class="h-2.5 w-2.5 rounded-full bg-warning-500/80"></span><span class="h-2.5 w-2.5 rounded-full bg-success-500/80"></span>
                    <span class="ml-3 truncate rounded-md bg-slate-100 px-3 py-1 text-[11px] text-slate-500">app.wispcontrol.mx/dashboard</span>
                </div>
                <div class="grid grid-cols-[52px_1fr]">
                    <div class="flex flex-col items-center gap-3 rounded-bl-2xl bg-navy-900 py-4">
                        @foreach (['home', 'users', 'banknotes', 'wifi', 'map', 'wrench-screwdriver'] as $i => $icon)
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $i === 0 ? 'bg-brand-600 text-white' : 'text-navy-300' }}"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" /></span>
                        @endforeach
                    </div>
                    <div class="p-4 sm:p-5">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold tracking-[0.14em] text-slate-500 uppercase">Resumen de hoy</span>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 px-2 py-0.5 text-[11px] font-semibold text-success-700"><span class="h-1.5 w-1.5 rounded-full bg-success-600"></span>En vivo</span>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            @foreach ([['Clientes activos', '842', '+32 este mes', 'text-success-600'], ['Ingreso mensual', '$294.7k', '+4.1 %', 'text-success-600'], ['Morosidad', '3.8 %', '−1.2 pts', 'text-success-600']] as [$k, $v, $s, $c])
                                <div class="rounded-xl bg-slate-50 p-2.5 ring-1 ring-slate-100">
                                    <div class="truncate text-[10px] font-medium text-slate-500">{{ $k }}</div>
                                    <div class="mt-1 text-base leading-none font-extrabold tracking-tight text-navy-900 sm:text-lg">{{ $v }}</div>
                                    <div class="mt-1 text-[10px] font-semibold {{ $c }}">{{ $s }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 rounded-xl bg-navy-900 p-3.5">
                            <div class="flex items-baseline justify-between">
                                <span class="text-[11px] font-medium text-navy-200">Cobranza del mes</span>
                                <span class="text-sm font-bold text-white">$287,450</span>
                            </div>
                            <div class="mt-3 flex h-20 items-end gap-1.5">
                                @foreach ([38, 52, 45, 61, 57, 70, 66, 78, 74, 85, 81, 94] as $i => $h)
                                    <span class="flex-1 rounded-sm {{ $i === 11 ? 'bg-brand-400' : 'bg-brand-600/60' }}" style="height: {{ $h }}%"></span>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-3 space-y-2">
                            @foreach ([['R-03 · San Isidro', 62, 'bg-success-500'], ['R-07 · El Pozo', 87, 'bg-warning-500']] as [$name, $pct, $bar])
                                <div class="flex items-center gap-3 text-[11px]">
                                    <span class="w-28 shrink-0 truncate font-semibold text-navy-900">{{ $name }}</span>
                                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full {{ $bar }}" style="width: {{ $pct }}%"></span></span>
                                    <span class="w-8 text-right font-semibold text-slate-500">{{ $pct }}%</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Notificaciones flotantes --}}
            <div class="absolute -top-8 -left-3 z-20 w-60 -rotate-3 sm:-left-12">
                <div class="flex items-start gap-3 rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-success-600 text-white"><x-heroicon-s-check class="h-5 w-5" /></span>
                    <div class="min-w-0 leading-tight">
                        <div class="text-[13px] font-bold text-navy-900">Pago recibido · $450</div>
                        <div class="mt-0.5 text-[11px] text-slate-500">Servicio reconectado en MikroTik</div>
                    </div>
                </div>
            </div>
            <div class="absolute top-1/2 -right-3 z-20 hidden w-56 rotate-2 sm:-right-10 sm:block">
                <div class="rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float" style="animation-delay:-2.3s">
                    <div class="flex items-center gap-2 text-[12px] font-bold text-navy-900">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-success-500 text-white"><x-heroicon-s-chat-bubble-oval-left class="h-3.5 w-3.5" /></span> WhatsApp enviado
                    </div>
                    <div class="mt-2 rounded-lg rounded-tl-none bg-success-50 px-2.5 py-2 text-[11px] leading-snug text-slate-700">Hola Juan, tu servicio vence mañana. Paga aquí en 1 minuto 👉 <span class="font-semibold text-brand-600">pagar/7Kx…</span></div>
                </div>
            </div>
            <div class="absolute -bottom-8 left-6 z-20 w-56 -rotate-1 sm:left-auto sm:-right-4">
                <div class="flex items-center gap-3 rounded-xl bg-white p-3 shadow-xl shadow-black/30 motion-safe:animate-float" style="animation-delay:-4.6s">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-heroicon-o-truck class="h-5 w-5" /></span>
                    <div class="leading-tight">
                        <div class="text-[13px] font-bold text-navy-900">Ticket #1258</div>
                        <div class="mt-0.5 text-[11px] text-slate-500">Técnico en camino · 12 min</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================ Integraciones --}}
<section class="border-b border-slate-100 bg-white py-8" aria-label="Integraciones">
    <p class="text-center text-[11px] font-bold tracking-[0.2em] text-slate-400 uppercase">Se conecta con lo que ya usas</p>
    <div class="relative mt-5 overflow-hidden" style="mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent)">
        <div class="lp-marquee flex w-max gap-4">
            @foreach (array_merge(...array_fill(0, 2, [['server-stack', 'MikroTik RouterOS'], ['chat-bubble-left-right', 'WhatsApp Cloud API'], ['credit-card', 'Mercado Pago'], ['credit-card', 'Stripe'], ['building-storefront', 'OXXO'], ['credit-card', 'PayPal'], ['device-phone-mobile', 'Twilio SMS'], ['bell-alert', 'Notificaciones push'], ['envelope', 'Correo electrónico'], ['code-bracket', 'API REST']])) as [$icon, $name])
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold whitespace-nowrap text-navy-700">
                    <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 text-brand-600" /> {{ $name }}
                </span>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ Antes / después --}}
<section class="bg-white py-24 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal mx-auto max-w-3xl text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">El problema</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Administrar un WISP a mano te está costando dinero.</h2>
            <p class="mt-5 text-lg text-slate-600">Cada pago que nadie cobró, cada cliente que siguió navegando sin pagar y cada visita técnica sin registro es dinero que se va. {{ $brand }} lo convierte en un proceso que se cuida solo.</p>
        </div>
        <div class="mt-16 grid gap-6 lg:grid-cols-2">
            <div class="reveal rounded-3xl border border-slate-200 bg-slate-50 p-7 sm:p-9">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-danger-100 text-danger-600"><x-heroicon-o-x-mark class="h-6 w-6" /></span>
                    <h3 class="text-xl font-bold text-navy-900">Sin control</h3>
                </div>
                <ul class="mt-7 space-y-4">
                    @foreach ([
                        'Cobros anotados en cuadernos y hojas de cálculo que nadie actualiza.',
                        'Cortes y reconexiones manuales en Winbox, uno por uno.',
                        'Recordatorios de pago enviados a mano por WhatsApp.',
                        'Técnicos sin ruta, sin evidencia y sin firma del cliente.',
                        'El efectivo de los cobradores sin conciliar.',
                        'No sabes qué repetidor está saturado ni qué zona es rentable.',
                    ] as $item)
                        <li class="flex gap-3 text-[15px] text-slate-600"><x-heroicon-s-x-circle class="mt-0.5 h-5 w-5 shrink-0 text-danger-500" /> {{ $item }}</li>
                    @endforeach
                </ul>
            </div>
            <div class="reveal relative overflow-hidden rounded-3xl bg-navy-950 p-7 sm:p-9" style="transition-delay:.12s">
                <div class="pointer-events-none absolute -top-24 -right-24 h-72 w-72 rounded-full bg-brand-600/40 blur-3xl" aria-hidden="true"></div>
                <div class="relative flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-success-600 text-white"><x-heroicon-o-check class="h-6 w-6" /></span>
                    <h3 class="text-xl font-bold text-white">Con {{ $brand }}</h3>
                </div>
                <ul class="relative mt-7 space-y-4">
                    @foreach ([
                        'Cargos generados solos cada periodo, con saldo al día por cliente.',
                        'Suspensión y reconexión automática en tus MikroTik.',
                        'Avisos automáticos por WhatsApp, SMS, correo y push con link de pago.',
                        'App de campo con ruta, fotos, GPS, materiales y firma.',
                        'Cortes de caja por cobrador: cobrado contra entregado.',
                        'Mapa de red, capacidad por repetidor y rentabilidad por zona.',
                    ] as $item)
                        <li class="flex gap-3 text-[15px] text-navy-100"><x-heroicon-s-check-circle class="mt-0.5 h-5 w-5 shrink-0 text-success-500" /> {{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================ Ciclo automático --}}
<section id="automatizacion" class="relative scroll-mt-20 overflow-hidden bg-navy-950 py-24 sm:py-28">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <div class="lp-grid absolute inset-0" style="mask-image: radial-gradient(ellipse at 50% 50%, #000 15%, transparent 70%)"></div>
        <div class="absolute top-1/2 left-1/2 h-[30rem] w-[60rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-600/15 blur-3xl"></div>
    </div>
    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal mx-auto max-w-3xl text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-300 uppercase">Automatización</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-white sm:text-5xl">El ciclo de cobranza que <span class="bg-gradient-to-r from-brand-300 to-teal-300 bg-clip-text text-transparent">se maneja solo</span>.</h2>
            <p class="mt-5 text-lg text-navy-200">Configuras tus reglas una vez: días de tolerancia, recargos y mensajes. A partir de ahí, {{ $brand }} cuida cada pago, las 24 horas.</p>
        </div>

        @php
            $flow = [
                ['bell-alert', 'Recordatorio', 'Antes del vencimiento, aviso por WhatsApp con su link de pago.', 'bg-brand-600'],
                ['clock', 'Vence', 'Se respetan tus días de tolerancia y se aplica el recargo.', 'bg-warning-600'],
                ['no-symbol', 'Corte automático', 'El cliente pasa a la lista de morosos en tu MikroTik.', 'bg-danger-600'],
                ['credit-card', 'Paga en línea', 'Tarjeta, OXXO, Mercado Pago, PayPal o con tu cobrador.', 'bg-purple-600'],
                ['arrow-path', 'Reconexión', 'Al confirmarse el pago, el servicio vuelve solo.', 'bg-success-600'],
            ];
        @endphp
        <div class="reveal relative mt-16">
            {{-- Línea conectora con pulso viajero (escritorio) --}}
            <div class="absolute top-8 right-[10%] left-[10%] hidden h-px lg:block" aria-hidden="true">
                <svg class="absolute inset-0 h-px w-full overflow-visible" preserveAspectRatio="none"><line x1="0" y1="0" x2="100%" y2="0" stroke="rgba(142,196,255,.45)" stroke-width="2" class="lp-dash"/></svg>
                <span class="lp-travel absolute -top-1.5 h-3 w-3 -translate-x-1/2 rounded-full bg-brand-300 shadow-[0_0_18px_6px_rgba(89,163,251,.6)]"></span>
            </div>
            <ol class="relative grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ($flow as $i => [$icon, $title, $text, $bg])
                    <li class="flex flex-col items-center text-center">
                        <span class="relative flex h-16 w-16 items-center justify-center rounded-2xl {{ $bg }} text-white shadow-lg ring-8 ring-navy-950">
                            <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-7 w-7" />
                            <span class="absolute -top-2 -right-2 flex h-6 w-6 items-center justify-center rounded-full bg-white text-[11px] font-extrabold text-navy-900">{{ $i + 1 }}</span>
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-white">{{ $title }}</h3>
                        <p class="mt-2 max-w-[15rem] text-sm leading-relaxed text-navy-200">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- Segundo flujo: incidencias de red --}}
        <div class="reveal mt-20 rounded-3xl bg-white/[0.04] p-6 ring-1 ring-white/10 sm:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
                <div class="lg:w-72 lg:shrink-0">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-danger-600/20 px-3 py-1 text-xs font-bold text-danger-100 ring-1 ring-danger-500/30"><span class="h-1.5 w-1.5 rounded-full bg-danger-500 motion-safe:animate-ping"></span>Alerta crítica</span>
                    <h3 class="mt-3 text-xl font-bold text-white">¿Se cayó un repetidor? Ya hay alguien en camino.</h3>
                </div>
                <div class="flex flex-1 flex-wrap items-center gap-2 text-sm font-semibold">
                    @foreach (['Repetidor desconectado', '85 clientes afectados', 'Ticket automático', 'Técnico de guardia asignado', 'Aviso masivo a los clientes'] as $i => $step)
                        @if ($i)<x-heroicon-m-chevron-right class="h-4 w-4 text-navy-400" />@endif
                        <span class="rounded-lg px-3 py-2 {{ $i === 0 ? 'bg-danger-600 text-white' : 'bg-white/10 text-navy-100 ring-1 ring-white/10' }}">{{ $step }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================ Módulos (bento) --}}
<section id="modulos" class="scroll-mt-20 bg-surface py-24 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal max-w-3xl">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Todo tu WISP</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Cinco áreas, una sola base de datos.</h2>
            <p class="mt-5 text-lg text-slate-600">Dinero, red, campo, clientes y crecimiento hablan entre sí. Lo que pasa en uno se refleja al instante en los demás.</p>
        </div>

        <div class="mt-14 grid gap-5 lg:grid-cols-6">
            {{-- Dinero --}}
            <article class="reveal group relative overflow-hidden rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-200/70 transition hover:-translate-y-1 hover:shadow-xl lg:col-span-4">
                <div class="flex flex-col gap-8 sm:flex-row">
                    <div class="sm:w-1/2">
                        <span class="inline-flex items-center gap-2 rounded-full bg-success-50 px-3 py-1 text-xs font-bold tracking-wide text-success-700 uppercase"><x-heroicon-o-banknotes class="h-4 w-4" />Dinero</span>
                        <h3 class="mt-4 text-2xl font-bold text-navy-900">Cobranza y pagos sin fugas</h3>
                        <p class="mt-3 text-slate-600">Cargos periódicos automáticos, pagos en línea, recibos en PDF, cortes de caja y comisiones calculadas en cada pago.</p>
                        <ul class="mt-5 grid gap-2 text-sm text-slate-600">
                            @foreach (['Link de pago por cliente', 'Promesas de pago y recargos', 'Estados de cuenta en PDF', 'Exporta a Excel, CSV o PDF'] as $f)
                                <li class="flex items-center gap-2"><x-heroicon-s-check-circle class="h-4 w-4 text-success-600" />{{ $f }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="flex-1 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-100">
                        <div class="text-[11px] font-bold tracking-wider text-slate-500 uppercase">Pagos de hoy</div>
                        @foreach ([['María López', 'Mercado Pago', '$450'], ['Pedro Sánchez', 'Efectivo · Cobrador', '$350'], ['Ana Ruiz', 'OXXO', '$599'], ['Luis Torres', 'Tarjeta', '$450']] as [$n, $m, $a])
                            <div class="mt-3 flex items-center justify-between rounded-xl bg-white px-3 py-2.5 shadow-xs ring-1 ring-slate-100">
                                <div class="leading-tight"><div class="text-sm font-semibold text-navy-900">{{ $n }}</div><div class="text-[11px] text-slate-500">{{ $m }}</div></div>
                                <span class="text-sm font-bold text-success-600">{{ $a }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </article>

            {{-- Red --}}
            <article class="reveal group relative overflow-hidden rounded-3xl bg-navy-900 p-7 text-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl lg:col-span-2" style="transition-delay:.08s">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold tracking-wide text-brand-200 uppercase"><x-heroicon-o-wifi class="h-4 w-4" />Red</span>
                <h3 class="mt-4 text-2xl font-bold">MikroTik, torres y repetidores</h3>
                <p class="mt-3 text-navy-200">Estado en vivo, capacidad por repetidor, equipos y antenas en el mapa.</p>
                <div class="relative mt-6 h-40 overflow-hidden rounded-2xl bg-navy-950 ring-1 ring-white/10" aria-hidden="true">
                    <div class="lp-grid absolute inset-0 opacity-60"></div>
                    @foreach ([[22, 30, 'bg-success-500'], [55, 22, 'bg-success-500'], [78, 48, 'bg-warning-500'], [38, 66, 'bg-success-500'], [66, 76, 'bg-danger-500'], [15, 72, 'bg-success-500']] as [$x, $y, $c])
                        <span class="absolute flex h-3 w-3 -translate-x-1/2 -translate-y-1/2" style="left: {{ $x }}%; top: {{ $y }}%">
                            <span class="absolute inline-flex h-full w-full rounded-full {{ $c }} opacity-60 motion-safe:animate-ping"></span>
                            <span class="relative inline-flex h-3 w-3 rounded-full {{ $c }} ring-2 ring-navy-950"></span>
                        </span>
                    @endforeach
                    <svg class="absolute inset-0 h-full w-full" preserveAspectRatio="none" viewBox="0 0 100 100"><polyline points="22,30 55,22 78,48 66,76 38,66 22,30" fill="none" stroke="rgba(89,163,251,.35)" stroke-width=".6" class="lp-dash"/><line x1="38" y1="66" x2="15" y2="72" stroke="rgba(89,163,251,.35)" stroke-width=".6"/></svg>
                </div>
            </article>

            {{-- Campo --}}
            <article class="reveal group rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-200/70 transition hover:-translate-y-1 hover:shadow-xl lg:col-span-2">
                <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-bold tracking-wide text-brand-700 uppercase"><x-heroicon-o-wrench-screwdriver class="h-4 w-4" />Campo</span>
                <h3 class="mt-4 text-2xl font-bold text-navy-900">Técnicos y cobradores</h3>
                <p class="mt-3 text-slate-600">Órdenes, tickets y rutas del día. Llegada y salida con GPS, fotos de antes y después, materiales y firma del cliente.</p>
                <div class="mt-6 flex flex-wrap gap-2">
                    @foreach (['camera' => 'Evidencia', 'map-pin' => 'Ruta', 'pencil-square' => 'Firma', 'cube' => 'Materiales'] as $icon => $label)
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-navy-700 ring-1 ring-slate-200"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4 text-brand-600" />{{ $label }}</span>
                    @endforeach
                </div>
            </article>

            {{-- Cliente --}}
            <article class="reveal group rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-200/70 transition hover:-translate-y-1 hover:shadow-xl lg:col-span-2" style="transition-delay:.08s">
                <span class="inline-flex items-center gap-2 rounded-full bg-purple-50 px-3 py-1 text-xs font-bold tracking-wide text-purple-700 uppercase"><x-heroicon-o-device-phone-mobile class="h-4 w-4" />Cliente</span>
                <h3 class="mt-4 text-2xl font-bold text-navy-900">App con tu marca</h3>
                <p class="mt-3 text-slate-600">Tus clientes ven su estado, pagan, descargan comprobantes, reportan fallas y reciben avisos. Menos llamadas, más pagos a tiempo.</p>
                <div class="mt-6 flex items-center gap-3 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-100">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-success-600 text-white"><x-heroicon-s-signal class="h-5 w-5" /></span>
                    <div class="leading-tight"><div class="text-sm font-bold text-navy-900">Internet: Activo</div><div class="text-xs text-slate-500">Hogar 30 Mbps · próximo pago $450</div></div>
                </div>
            </article>

            {{-- Crecimiento --}}
            <article class="reveal group rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 p-7 text-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl lg:col-span-2" style="transition-delay:.16s">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-bold tracking-wide uppercase"><x-heroicon-o-arrow-trending-up class="h-4 w-4" />Crecimiento</span>
                <h3 class="mt-4 text-2xl font-bold">Vende más, en el lugar correcto</h3>
                <p class="mt-3 text-brand-100">Prospectos, referidos, portal cautivo con publicidad local y BI que te dice qué zona conviene expandir.</p>
                <div class="mt-6 flex h-16 items-end gap-1.5" aria-hidden="true">
                    @foreach ([30, 42, 38, 55, 62, 58, 74, 88, 100] as $h)
                        <span class="flex-1 rounded-sm bg-white/30" style="height: {{ $h }}%"></span>
                    @endforeach
                </div>
            </article>
        </div>
    </div>
</section>

{{-- ============================================================ Números --}}
<section class="bg-white py-20">
    <div class="mx-auto grid max-w-7xl grid-cols-2 gap-8 px-4 sm:px-6 lg:grid-cols-4 lg:px-8">
        @foreach ([[5000, '+', 'clientes por WISP', 'Escala desde 50 hasta miles de clientes.'], [4, '', 'canales de aviso', 'WhatsApp, SMS, correo y push.'], [8, '', 'roles con permisos', 'Cada quien ve solo lo que le toca.'], [24, '/7', 'automatizaciones', 'Cortes, avisos y alertas sin descanso.']] as [$n, $suffix, $label, $hint])
            <div class="reveal text-center lg:text-left">
                <div class="text-4xl font-extrabold tracking-tight text-navy-900 sm:text-5xl"><span data-count="{{ $n }}">{{ number_format($n) }}</span><span class="text-brand-600">{{ $suffix }}</span></div>
                <div class="mt-2 text-sm font-bold tracking-wide text-navy-700 uppercase">{{ $label }}</div>
                <p class="mt-1 text-sm text-slate-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ============================================================ Cómo funciona --}}
<section id="como-funciona" class="scroll-mt-20 bg-surface py-24 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal mx-auto max-w-3xl text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Cómo funciona</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">En marcha en tres pasos.</h2>
        </div>
        <div class="mt-16 grid gap-6 md:grid-cols-3">
            @foreach ([
                ['building-office-2', 'Da de alta tu WISP', 'Logotipo, colores, planes de internet, métodos de pago y políticas de corte. Tu información queda separada de cualquier otra empresa.'],
                ['server-stack', 'Conecta tu red', 'Agrega tus MikroTik, torres y repetidores, e importa tus clientes con su IP, MAC y usuario PPPoE.'],
                ['rocket-launch', 'Activa la automatización', 'Recordatorios, cortes, reconexiones y alertas empiezan a trabajar solos. Tú te enfocas en crecer.'],
            ] as $i => [$icon, $title, $text])
                <div class="reveal relative rounded-3xl bg-white p-8 shadow-sm ring-1 ring-slate-200/70" style="transition-delay: {{ $i * 0.1 }}s">
                    <span class="absolute top-6 right-7 text-6xl font-black text-slate-100 select-none">0{{ $i + 1 }}</span>
                    <span class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-navy-900 text-brand-300 shadow-lg"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-7 w-7" /></span>
                    <h3 class="relative mt-6 text-xl font-bold text-navy-900">{{ $title }}</h3>
                    <p class="relative mt-3 leading-relaxed text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ Para quién --}}
<section id="para-quien" class="scroll-mt-20 bg-white py-24 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal mx-auto max-w-3xl text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Para quién</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Cada persona, su herramienta.</h2>
        </div>

        @php
            $audiences = [
                'admin' => ['Dueño y administración', 'presentation-chart-line', 'Toma decisiones con números reales', [
                    'Tablero con ingresos, morosidad y crecimiento en vivo.',
                    'Roles para finanzas, soporte, supervisión y campo.',
                    'Rentabilidad por zona y por repetidor.',
                    'Bitácora de quién hizo qué y cuándo.',
                ]],
                'campo' => ['Técnicos y cobradores', 'map-pin', 'Su día completo en el celular', [
                    'Ruta sugerida con navegación a cada domicilio.',
                    'Registro de llegada, salida, fotos y firma.',
                    'Cobro en campo con folio y evidencia.',
                    'Corte de caja al final del día.',
                ]],
                'clientes' => ['Tus clientes', 'user-group', 'Atención que no satura tu teléfono', [
                    'Consultan su saldo y su plan desde la app.',
                    'Pagan en línea a cualquier hora.',
                    'Reportan fallas y siguen su ticket.',
                    'Reciben avisos de pago, mantenimiento y técnico en camino.',
                ]],
            ];
        @endphp
        <div class="reveal mt-12" data-tabs>
            <div class="mx-auto flex max-w-2xl flex-wrap justify-center gap-2 rounded-2xl bg-slate-100 p-1.5" role="tablist">
                @foreach ($audiences as $key => [$label, $icon])
                    <button type="button" role="tab" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold whitespace-nowrap text-slate-600 transition aria-selected:bg-white aria-selected:text-navy-900 aria-selected:shadow-sm">
                        <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-4 w-4" />{{ $label }}
                    </button>
                @endforeach
            </div>

            @foreach ($audiences as $key => [$label, $icon, $headline, $items])
                <div data-panel="{{ $key }}" role="tabpanel" @class(['mt-10 grid items-center gap-10 lg:grid-cols-2', 'hidden' => ! $loop->first])>
                    <div>
                        <h3 class="text-3xl font-extrabold tracking-tight text-navy-900">{{ $headline }}</h3>
                        <ul class="mt-6 space-y-4">
                            @foreach ($items as $item)
                                <li class="flex gap-3 text-lg text-slate-600"><span class="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600"><x-heroicon-m-check class="h-4 w-4" /></span>{{ $item }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ $demoUrl }}" {!! $demoAttrs !!} class="mt-8 inline-flex items-center gap-2 font-semibold text-brand-600 hover:text-brand-700">Quiero verlo en mi WISP <x-heroicon-m-arrow-right class="h-4 w-4" /></a>
                    </div>
                    <div class="relative flex justify-center">
                        <div class="pointer-events-none absolute inset-0 m-auto h-72 w-72 rounded-full bg-brand-200/50 blur-3xl" aria-hidden="true"></div>
                        @if ($key === 'admin')
                            <div class="relative w-full max-w-md rounded-3xl bg-navy-900 p-6 shadow-2xl">
                                <div class="text-[11px] font-bold tracking-wider text-navy-300 uppercase">Rentabilidad por zona</div>
                                @foreach ([['Zona Centro', 92, '$84.2k'], ['Zona Norte', 78, '$66.9k'], ['Zona Oriente', 64, '$51.3k'], ['Zona Sur', 41, '$32.7k']] as [$z, $w, $v])
                                    <div class="mt-4">
                                        <div class="flex justify-between text-sm"><span class="font-semibold text-white">{{ $z }}</span><span class="font-bold text-brand-300">{{ $v }}</span></div>
                                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-white/10"><div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-teal-500" style="width: {{ $w }}%"></div></div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="relative w-64 rounded-[2.4rem] bg-navy-950 p-2.5 shadow-2xl ring-1 ring-white/10">
                                <div class="overflow-hidden rounded-[1.9rem] bg-surface">
                                    <div class="flex justify-center bg-navy-900 pt-2 pb-4"><span class="h-1.5 w-16 rounded-full bg-white/20"></span></div>
                                    @if ($key === 'campo')
                                        <div class="-mt-2 rounded-t-2xl bg-surface p-4">
                                            <div class="text-xs font-bold text-slate-500">Ruta de hoy · 6 paradas</div>
                                            @foreach ([['Instalación', 'Calle Hidalgo 12', 'bg-brand-600', 'Llegué'], ['Falla de señal', 'Av. Juárez 88', 'bg-warning-600', 'Pendiente'], ['Cobro $450', 'Col. El Pozo', 'bg-success-600', 'Pendiente']] as [$t, $a, $c, $s])
                                                <div class="mt-3 flex items-center gap-3 rounded-xl bg-white p-3 shadow-xs ring-1 ring-slate-100">
                                                    <span class="h-9 w-1.5 rounded-full {{ $c }}"></span>
                                                    <div class="min-w-0 flex-1 leading-tight"><div class="text-[13px] font-bold text-navy-900">{{ $t }}</div><div class="truncate text-[11px] text-slate-500">{{ $a }}</div></div>
                                                    <span class="text-[10px] font-semibold text-slate-400">{{ $s }}</span>
                                                </div>
                                            @endforeach
                                            <div class="mt-4 flex items-center justify-center gap-2 rounded-xl bg-brand-600 py-2.5 text-sm font-bold text-white"><x-heroicon-o-camera class="h-4 w-4" />Subir evidencia</div>
                                        </div>
                                    @else
                                        <div class="-mt-2 rounded-t-2xl bg-surface p-4">
                                            <div class="text-sm font-bold text-navy-900">Hola, Juan 👋</div>
                                            <div class="mt-3 rounded-2xl bg-navy-900 p-4 text-white">
                                                <div class="flex items-center gap-2 text-xs font-semibold text-success-500"><span class="h-2 w-2 rounded-full bg-success-500"></span>Internet activo</div>
                                                <div class="mt-2 text-xs text-navy-200">Próximo pago · 10 de octubre</div>
                                                <div class="text-2xl font-extrabold">$450.00</div>
                                                <div class="mt-3 rounded-xl bg-brand-600 py-2 text-center text-sm font-bold">Pagar ahora</div>
                                            </div>
                                            <div class="mt-3 grid grid-cols-2 gap-2">
                                                @foreach (['wrench-screwdriver' => 'Reportar falla', 'document-text' => 'Comprobantes', 'gift' => 'Referir', 'chat-bubble-left-right' => 'Soporte'] as $icon => $label)
                                                    <div class="flex flex-col items-center gap-1 rounded-xl bg-white p-2.5 text-[11px] font-semibold text-navy-700 shadow-xs ring-1 ring-slate-100"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5 text-brand-600" />{{ $label }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ Planes --}}
<section id="planes" class="scroll-mt-20 bg-surface py-24 sm:py-28">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal mx-auto max-w-3xl text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Planes</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Un plan para cada etapa de tu WISP.</h2>
            <p class="mt-5 text-lg text-slate-600">Empieza con lo esencial y crece cuando tu red crezca. Precios en pesos mexicanos.</p>
        </div>
        <div @class(['mt-16 grid gap-6', 'lg:grid-cols-3' => count($plans) >= 3, 'lg:grid-cols-2' => count($plans) === 2])>
            @foreach ($plans as $plan)
                @php $featured = count($plans) > 1 && $loop->iteration === 2; @endphp
                <div @class([
                    'reveal relative flex flex-col rounded-3xl p-8',
                    'bg-navy-950 text-white shadow-2xl shadow-brand-900/30 ring-2 ring-brand-500 lg:-my-4 lg:py-12' => $featured,
                    'bg-white shadow-sm ring-1 ring-slate-200/70' => ! $featured,
                ]) style="transition-delay: {{ $loop->index * 0.1 }}s">
                    @if ($featured)
                        <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 px-4 py-1 text-xs font-bold tracking-wide text-white uppercase shadow-lg">Recomendado</span>
                    @endif
                    <h3 class="text-lg font-bold {{ $featured ? 'text-brand-300' : 'text-brand-600' }}">{{ $plan['name'] }}</h3>
                    <p class="mt-1 text-sm {{ $featured ? 'text-navy-200' : 'text-slate-500' }}">{{ $plan['description'] }}</p>
                    <div class="mt-6 flex items-baseline gap-1">
                        <span class="text-5xl font-extrabold tracking-tight {{ $featured ? 'text-white' : 'text-navy-900' }}">${{ number_format((float) $plan['price_monthly']) }}</span>
                        <span class="text-sm font-medium {{ $featured ? 'text-navy-200' : 'text-slate-500' }}">MXN / mes</span>
                    </div>
                    <div class="mt-6 grid grid-cols-3 gap-2 text-center">
                        @foreach ([['Clientes', $plan['max_clients'] ? number_format($plan['max_clients']).(($plan['max_clients'] >= 5000) ? '+' : '') : 'Ilimitados'], ['Equipos', $plan['max_devices'] ? number_format($plan['max_devices']) : 'Ilimitados'], ['Usuarios', $plan['max_users'] ? number_format($plan['max_users']) : 'Ilimitados']] as [$k, $v])
                            <div class="rounded-xl px-1 py-2.5 {{ $featured ? 'bg-white/5 ring-1 ring-white/10' : 'bg-slate-50 ring-1 ring-slate-100' }}">
                                <div class="text-sm font-extrabold {{ $featured ? 'text-white' : 'text-navy-900' }}">{{ $v }}</div>
                                <div class="text-[11px] {{ $featured ? 'text-navy-300' : 'text-slate-500' }}">{{ $k }}</div>
                            </div>
                        @endforeach
                    </div>
                    <ul class="mt-7 flex-1 space-y-3">
                        @foreach ($plan['feature_labels'] as $feature)
                            <li class="flex items-center gap-2.5 text-sm {{ $featured ? 'text-navy-100' : 'text-slate-600' }}"><x-heroicon-s-check-circle class="h-5 w-5 shrink-0 {{ $featured ? 'text-brand-400' : 'text-brand-600' }}" />{{ $feature }}</li>
                        @endforeach
                    </ul>
                    @if ((float) ($plan['commission_percent'] ?? 0) > 0)
                        <p class="mt-6 text-xs {{ $featured ? 'text-navy-300' : 'text-slate-400' }}">Comisión de {{ rtrim(rtrim(number_format((float) $plan['commission_percent'], 2), '0'), '.') }} % por pago en línea.</p>
                    @endif
                    <a href="{{ $demoUrl }}" {!! $demoAttrs !!} @class([
                        'mt-6 inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold transition',
                        'bg-brand-600 text-white shadow-lg shadow-brand-600/30 hover:bg-brand-500' => $featured,
                        'bg-navy-900 text-white hover:bg-navy-800' => ! $featured,
                    ])>Agenda una demo <x-heroicon-m-arrow-right class="h-4 w-4" /></a>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ Seguridad --}}
<section id="seguridad" class="scroll-mt-20 bg-white py-24 sm:py-28">
    <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div class="reveal">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Seguridad y confianza</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Tu información y la de tus clientes, protegida.</h2>
            <p class="mt-5 text-lg text-slate-600">Cada WISP opera con su información completamente separada. Tú decides quién ve qué, y queda registro de cada cambio.</p>
            <a href="{{ route('login') }}" class="mt-8 inline-flex items-center gap-2 font-semibold text-brand-600 hover:text-brand-700">¿Ya eres cliente? Inicia sesión <x-heroicon-m-arrow-right class="h-4 w-4" /></a>
        </div>
        <div class="reveal grid gap-4 sm:grid-cols-2" style="transition-delay:.1s">
            @foreach ([
                ['building-office-2', 'Datos aislados por WISP', 'Ninguna empresa puede ver la información de otra.'],
                ['key', 'Roles y permisos', 'Administración, finanzas, soporte, supervisión, técnicos y cobradores.'],
                ['clipboard-document-list', 'Bitácora de auditoría', 'Quién hizo qué cambio, cuándo y desde dónde.'],
                ['lock-closed', 'Credenciales cifradas', 'Contraseñas PPPoE y llaves de pasarelas protegidas.'],
                ['circle-stack', 'Respaldos', 'Copias de la base de datos listas para restaurar.'],
                ['code-bracket', 'API para integraciones', 'Conecta tu contabilidad, CRM o ERP.'],
            ] as [$icon, $title, $text])
                <div class="rounded-2xl bg-surface p-5 ring-1 ring-slate-200/70">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-navy-900 text-brand-300"><x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5" /></span>
                    <h3 class="mt-4 font-bold text-navy-900">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ Preguntas --}}
<section id="preguntas" class="scroll-mt-20 bg-surface py-24 sm:py-28">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div class="reveal text-center">
            <span class="text-sm font-bold tracking-[0.16em] text-brand-600 uppercase">Preguntas frecuentes</span>
            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-navy-900 sm:text-5xl">Lo que nos preguntan los WISP.</h2>
        </div>
        <div class="reveal mt-12 space-y-3">
            @foreach ([
                ['¿Funciona con mis equipos MikroTik?', 'Sí. '.$brand.' se conecta a tus routers MikroTik por la API de RouterOS para suspender y reconectar clientes, y consultar el estado de tu red.'],
                ['¿Necesito instalar algo?', 'No. El panel funciona desde el navegador en computadora, tablet o celular. Técnicos, cobradores y clientes cuentan con su propia app.'],
                ['¿Cómo pagan mis clientes?', 'En línea con tarjeta, OXXO, Mercado Pago o PayPal desde su link de pago, o en efectivo con tu cobrador, que registra el cobro con folio y evidencia.'],
                ['¿Puedo usar mi propia marca?', 'Sí. Configuras tu logotipo y colores; en el plan Enterprise la plataforma y la app del cliente se presentan con tu marca (marca blanca).'],
                ['¿Puedo migrar mis clientes actuales?', 'Sí. Puedes dar de alta a tus clientes con su plan, IP, MAC, usuario PPPoE, zona y repetidor, y empezar a cobrar desde el primer periodo.'],
                ['¿Mi información está separada de la de otros WISP?', 'Totalmente. Cada empresa opera con información independiente, con sus propios usuarios, roles y permisos.'],
            ] as [$q, $a])
                <details class="group rounded-2xl bg-white p-5 shadow-xs ring-1 ring-slate-200/70 open:ring-brand-200 sm:p-6">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-left font-bold text-navy-900 [&::-webkit-details-marker]:hidden">
                        {{ $q }}
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-open:rotate-45 group-open:bg-brand-600 group-open:text-white"><x-heroicon-m-plus class="h-5 w-5" /></span>
                    </summary>
                    <p class="mt-3 leading-relaxed text-slate-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================================================ CTA final --}}
<section class="relative overflow-hidden bg-navy-950 py-24 sm:py-28">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <div class="lp-grid absolute inset-0" style="mask-image: radial-gradient(ellipse at 50% 50%, #000 20%, transparent 70%)"></div>
        <div class="lp-glow absolute top-1/2 left-1/2 h-[28rem] w-[50rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-600/30 blur-3xl"></div>
        <div class="absolute top-1/2 left-1/2">
            @foreach (['0s', '1.2s', '2.4s'] as $delay)
                <span class="absolute -top-[260px] -left-[260px] h-[520px] w-[520px] rounded-full border border-brand-400/20 motion-safe:animate-signal" style="animation-delay: {{ $delay }}"></span>
            @endforeach
        </div>
    </div>
    <div class="reveal relative mx-auto max-w-3xl px-4 text-center sm:px-6">
        <h2 class="text-4xl font-extrabold tracking-tight text-white sm:text-6xl">¿Listo para tomar el control de tu WISP?</h2>
        <p class="mx-auto mt-6 max-w-xl text-lg text-navy-200">Te mostramos {{ $brand }} funcionando con datos reales de un WISP, en una llamada de 30 minutos.</p>
        <div class="mt-10 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ $demoUrl }}" {!! $demoAttrs !!} class="group inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-7 py-4 text-base font-bold text-white shadow-xl shadow-brand-600/30 transition hover:-translate-y-0.5 hover:bg-brand-500">
                Agenda tu demo gratuita <x-heroicon-m-arrow-right class="h-5 w-5 transition group-hover:translate-x-1" />
            </a>
            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl bg-white/5 px-7 py-4 text-base font-semibold text-white ring-1 ring-white/20 transition hover:bg-white/10">Iniciar sesión</a>
        </div>
    </div>
</section>
</main>

{{-- ============================================================ Pie --}}
<footer class="bg-navy-950 text-navy-200">
    <div class="mx-auto max-w-7xl border-t border-white/10 px-4 py-14 sm:px-6 lg:px-8">
        <div class="grid gap-10 md:grid-cols-[1.4fr_1fr_1fr]">
            <div>
                <x-brand.logo />
                <p class="mt-4 max-w-sm text-sm leading-relaxed">Plataforma integral para proveedores de internet inalámbrico y redes comunitarias: cobranza, red, campo, clientes y crecimiento.</p>
            </div>
            <div>
                <h3 class="text-sm font-bold text-white">Producto</h3>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @foreach (['#automatizacion' => 'Automatización', '#modulos' => 'Módulos', '#planes' => 'Planes', '#seguridad' => 'Seguridad', '#preguntas' => 'Preguntas frecuentes'] as $href => $label)
                        <li><a href="{{ $href }}" class="transition hover:text-white">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h3 class="text-sm font-bold text-white">Accesos</h3>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ route('login') }}" class="transition hover:text-white">Iniciar sesión</a></li>
                    <li><a href="{{ $demoUrl }}" {!! $demoAttrs !!} class="transition hover:text-white">Agenda una demo</a></li>
                    <li><a href="{{ url('/api/docs') }}" class="transition hover:text-white">Documentación de la API</a></li>
                </ul>
            </div>
        </div>
        <div class="mt-12 flex flex-col items-center justify-between gap-6 border-t border-white/10 pt-8 sm:flex-row">
            <p class="text-xs">© {{ now()->year }} {{ $brand }}. Todos los derechos reservados.</p>
            <div class="flex items-center gap-3 text-xs">
                <span>Un producto de</span>
                <span class="rounded-lg bg-white px-3 py-1.5"><img src="{{ asset('images/impactos-digitales.png') }}" alt="Impactos Digitales" class="h-7 w-auto" loading="lazy"></span>
            </div>
        </div>
    </div>
</footer>

<script>
    (() => {
        // Barra de navegación: fondo al hacer scroll
        const nav = document.querySelector('[data-nav]');
        const setNav = () => {
            const scrolled = window.scrollY > 24;
            nav.classList.toggle('bg-navy-950/90', scrolled);
            nav.classList.toggle('backdrop-blur', scrolled);
            nav.classList.toggle('shadow-lg', scrolled);
        };
        setNav();
        window.addEventListener('scroll', setNav, { passive: true });

        // Menú móvil
        const toggle = document.querySelector('[data-menu-toggle]');
        const menu = document.querySelector('[data-menu]');
        toggle.addEventListener('click', () => {
            const open = menu.classList.toggle('hidden') === false;
            toggle.setAttribute('aria-expanded', open);
            if (open) nav.classList.add('bg-navy-950/90');
        });
        menu.querySelectorAll('[data-menu-link]').forEach((a) => a.addEventListener('click', () => menu.classList.add('hidden')));

        // Pestañas "Para quién"
        document.querySelectorAll('[data-tabs]').forEach((root) => {
            const tabs = root.querySelectorAll('[data-tab]');
            tabs.forEach((tab) => tab.addEventListener('click', () => {
                tabs.forEach((t) => t.setAttribute('aria-selected', t === tab));
                root.querySelectorAll('[data-panel]').forEach((p) => p.classList.toggle('hidden', p.dataset.panel !== tab.dataset.tab));
            }));
        });

        // Aparición al hacer scroll y contadores
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const count = (el) => {
            const target = +el.dataset.count;
            if (reduce) return;
            const start = performance.now();
            const step = (now) => {
                const p = Math.min((now - start) / 1400, 1);
                el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('es-MX');
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        if (!('IntersectionObserver' in window)) {
            document.querySelectorAll('.reveal').forEach((el) => el.classList.add('is-visible'));
            return;
        }
        const io = new IntersectionObserver((entries) => entries.forEach((e) => {
            if (!e.isIntersecting) return;
            e.target.classList.add('is-visible');
            e.target.querySelectorAll('[data-count]').forEach(count);
            io.unobserve(e.target);
        }), { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.reveal').forEach((el) => io.observe(el));
    })();
</script>
</body>
</html>
