{{-- Portal cautivo público con la marca del WISP: acceso, avisos, promociones, planes y publicidad local. --}}
@php
    $brand = $tenant->primary_color ?: '#1A7FF5';
    $labels = config('wisp.growth.captive_login_methods');
    $icons = ['usuario' => 'user', 'codigo' => 'identification', 'voucher' => 'ticket'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $cfg['title'] ?: 'Bienvenido' }} · {{ $tenant->name }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css'])
    <style>
        :root { --cp-brand: {{ $brand }}; }
        .cp-bg { background-color: {{ $background }}; @if ($backgroundImage) background-image: linear-gradient(rgba(11,27,58,.55), rgba(11,27,58,.75)), url('{{ $backgroundImage }}'); background-size: cover; background-position: center; @endif }
        .cp-btn { background-color: var(--cp-brand); }
        .cp-btn:hover { filter: brightness(.94); }
        .cp-text { color: var(--cp-brand); }
        .cp-tab[aria-selected="true"] { background-color: var(--cp-brand); color: #fff; }
        .cp-input:focus { border-color: var(--cp-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--cp-brand) 18%, transparent); outline: none; }
    </style>
</head>
<body class="cp-bg min-h-screen font-sans text-slate-800">
    <main class="mx-auto max-w-md px-4 py-8">
        <div class="mb-6 flex flex-col items-center text-center text-white">
            @if ($tenant->logoUrl())
                <img src="{{ $tenant->logoUrl() }}" alt="{{ $tenant->name }}" class="h-16 w-16 rounded-2xl bg-white object-contain p-1.5 shadow-lg">
            @else
                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/15 text-xl font-bold text-white">{{ initials($tenant->name) }}</span>
            @endif
            <h1 class="mt-3 text-2xl font-bold">{{ $cfg['title'] ?: 'Bienvenido' }}</h1>
            @if ($cfg['welcome'])<p class="mt-1 text-sm text-white/80">{{ $cfg['welcome'] }}</p>@endif
        </div>

        @if (! empty($cfg['notice']))
            <div class="mb-4 flex gap-2 rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800">
                <x-heroicon-o-megaphone class="h-5 w-5 shrink-0" /> <span>{{ $cfg['notice'] }}</span>
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl bg-white shadow-xl">
            @if (count($methods) > 1)
                <div class="flex gap-1 border-b border-slate-100 p-2" role="tablist">
                    @foreach ($methods as $m)
                        <button type="button" role="tab" data-method="{{ $m }}" aria-selected="{{ $m === $method ? 'true' : 'false' }}"
                                class="cp-tab flex flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold text-slate-600 transition">
                            <x-dynamic-component :component="'heroicon-o-'.$icons[$m]" class="h-4 w-4" /> {{ ['usuario' => 'Usuario', 'codigo' => 'Cliente', 'voucher' => 'Ficha'][$m] }}
                        </button>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('captive.login', $tenant->slug) }}" class="space-y-4 p-5">
                @csrf
                <input type="hidden" name="method" id="cp-method" value="{{ $method }}">
                @foreach ($hotspot as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach

                @if (! empty($loginError) || $mikrotikError || $errors->any())
                    <div class="flex gap-2 rounded-lg bg-danger-50 px-3 py-2.5 text-sm text-danger-700">
                        <x-heroicon-o-exclamation-circle class="h-5 w-5 shrink-0" />
                        <span>{{ $loginError ?? ($errors->first() ?: $mikrotikError) }}</span>
                    </div>
                @endif

                <div data-panel="usuario" @class(['space-y-3', 'hidden' => $method !== 'usuario'])>
                    <p class="text-sm text-slate-500">Entra con el usuario y contraseña de tu servicio.</p>
                    <input name="username" value="{{ request('username') }}" maxlength="80" pattern="[A-Za-z0-9._@\-]+" title="Letras, números, punto, guion, guion bajo o @" autocomplete="username" autocapitalize="none" placeholder="Usuario" class="cp-input form-input">
                    <input name="password" type="password" maxlength="80" autocomplete="current-password" placeholder="Contraseña" class="cp-input form-input">
                </div>
                <div data-panel="codigo" @class(['space-y-3', 'hidden' => $method !== 'codigo'])>
                    <p class="text-sm text-slate-500">Escribe tu número de cliente; lo encuentras en tu recibo o estado de cuenta.</p>
                    <input name="code" value="{{ request('code') }}" maxlength="30" pattern="[A-Za-z0-9_\-]+" title="Letras, números y guiones" autocapitalize="characters" placeholder="Código de cliente" class="cp-input form-input font-mono uppercase tracking-wider">
                </div>
                <div data-panel="voucher" @class(['space-y-3', 'hidden' => $method !== 'voucher'])>
                    <p class="text-sm text-slate-500">Escribe el código de tu ficha de Internet.</p>
                    <input name="voucher" value="{{ request('voucher') }}" maxlength="30" pattern="[A-Za-z0-9 \-]+" title="Letras y números" autocapitalize="characters" autocomplete="off" placeholder="Ej. 7XK2MP9Q" class="cp-input form-input text-center font-mono text-lg uppercase tracking-[0.3em]">
                </div>

                @if ($cfg['terms'])
                    <label class="flex items-start gap-2 text-xs text-slate-500">
                        <input type="checkbox" name="accept" value="1" class="mt-0.5 rounded border-slate-300" @checked(request('accept'))>
                        <span>Acepto los términos de uso. <button type="button" class="cp-text font-semibold" onclick="document.getElementById('cp-terms').classList.toggle('hidden')">Ver términos</button></span>
                    </label>
                    <div id="cp-terms" class="hidden max-h-40 overflow-y-auto whitespace-pre-line rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ $cfg['terms'] }}</div>
                @endif

                <button type="submit" class="cp-btn flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">
                    <x-heroicon-o-wifi class="h-5 w-5" /> Conectarme
                </button>
            </form>
        </div>

        @if ($ads->isNotEmpty())
            <section class="mt-6">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-white/70">Negocios de tu comunidad</h2>
                <div class="space-y-3">
                    @foreach ($ads as $ad)
                        @php $tag = $ad->url ? 'a' : 'div'; @endphp
                        <{{ $tag }} @if ($ad->url) href="{{ route('captive.ad', [$tenant->slug, $ad->id]) }}" target="_blank" rel="noopener sponsored" @endif
                           class="flex gap-3 overflow-hidden rounded-xl bg-white p-3 shadow-md">
                            @if ($ad->imageUrl())
                                <img src="{{ $ad->imageUrl() }}" alt="" class="h-16 w-16 shrink-0 rounded-lg object-cover">
                            @endif
                            <div class="min-w-0">
                                <div class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ $ad->advertiser }}</div>
                                <div class="font-semibold text-slate-800">{{ $ad->title }}</div>
                                @if ($ad->body)<p class="text-xs text-slate-500">{{ $ad->body }}</p>@endif
                            </div>
                        </{{ $tag }}>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($promotions->isNotEmpty())
            <section class="mt-6">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-white/70">Promociones</h2>
                <div class="space-y-2">
                    @foreach ($promotions as $promo)
                        <div class="rounded-xl bg-white/95 px-4 py-3 shadow-md">
                            <div class="flex items-center gap-2 font-semibold text-slate-800"><x-heroicon-o-gift class="cp-text h-4 w-4" /> {{ $promo->name }}</div>
                            @if ($promo->description)<p class="mt-0.5 text-xs text-slate-500">{{ $promo->description }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($plans->isNotEmpty())
            <section class="mt-6">
                <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-white/70">Internet en tu casa</h2>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($plans as $p)
                        <div class="rounded-xl bg-white/95 p-3 shadow-md">
                            <div class="text-sm font-semibold text-slate-800">{{ $p->name }}</div>
                            <div class="text-xs text-slate-500">{{ (float) $p->download_mbps }} Mbps</div>
                            <div class="mt-1 font-bold text-navy-900">{{ money($p->price, false) }}<span class="text-xs font-medium text-slate-400">/mes</span></div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <footer class="mt-8 text-center text-xs text-white/70">
            @if (! empty($cfg['contact']))
                <p class="whitespace-pre-line">{{ $cfg['contact'] }}</p>
            @elseif ($tenant->whatsapp || $tenant->phone)
                <p>¿Necesitas ayuda? {{ $tenant->whatsapp ?: $tenant->phone }}</p>
            @endif
            <p class="mt-1">{{ $tenant->name }} · {{ config('wisp.brand.name') }}</p>
        </footer>
    </main>

    <script>
        document.querySelectorAll('[data-method]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var m = tab.dataset.method;
                document.getElementById('cp-method').value = m;
                document.querySelectorAll('[data-method]').forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
                document.querySelectorAll('[data-panel]').forEach(function (p) { p.classList.toggle('hidden', p.dataset.panel !== m); });
            });
        });
    </script>
</body>
</html>
