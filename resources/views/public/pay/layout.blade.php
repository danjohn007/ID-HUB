{{-- Layout público de pago con la marca del WISP (logo, nombre y color corporativo). --}}
@php $brand = $tenant->primary_color ?: '#1A7FF5'; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $pageTitle ?? 'Pagar' }} · {{ $tenant->name }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css'])
    <style>
        :root { --pay-brand: {{ $brand }}; }
        .pay-brand-bg { background-color: var(--pay-brand); }
        .pay-brand-text { color: var(--pay-brand); }
        .pay-brand-ring:focus-within, .pay-option:has(input:checked) { border-color: var(--pay-brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--pay-brand) 18%, transparent); }
        .pay-btn { background-color: var(--pay-brand); }
        .pay-btn:hover { filter: brightness(0.94); }
    </style>
</head>
<body class="min-h-screen bg-surface font-sans text-slate-800">
    <header class="pay-brand-bg">
        <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-4">
            @if ($tenant->logoUrl())
                <img src="{{ $tenant->logoUrl() }}" alt="{{ $tenant->name }}" class="h-10 w-10 rounded-lg bg-white object-contain p-1">
            @else
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white/20 text-sm font-bold text-white">{{ initials($tenant->name) }}</span>
            @endif
            <div class="min-w-0 text-white">
                <div class="truncate text-base font-bold">{{ $tenant->name }}</div>
                <div class="text-xs text-white/80">{{ $headerSubtitle ?? 'Pago seguro de tu servicio de Internet' }}</div>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-xl px-4 py-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-xl px-4 pb-8 text-center text-xs text-slate-400">
        @if ($tenant->phone || $tenant->whatsapp)
            <p>¿Dudas? Contáctanos al {{ $tenant->whatsapp ?: $tenant->phone }}</p>
        @endif
        <p class="mt-1 flex items-center justify-center gap-1"><x-heroicon-o-lock-closed class="h-3.5 w-3.5" /> Procesado por {{ config('wisp.brand.name') }}</p>
    </footer>
</body>
</html>
