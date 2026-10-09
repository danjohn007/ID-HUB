{{-- Acceso validado: entrega las credenciales al hotspot MikroTik (link-login-only) para abrir la navegación. --}}
@php $brand = $tenant->primary_color ?: '#1A7FF5'; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Conectando… · {{ $tenant->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-surface px-4 font-sans text-slate-800">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-xl">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success-50"><x-heroicon-s-check-circle class="h-11 w-11 text-success-600" /></span>
        <h1 class="mt-4 text-xl font-bold text-navy-900">{{ $client ? '¡Hola, '.$client->first_name.'!' : '¡Listo!' }}</h1>

        @if ($loginUrl)
            <p class="mt-1 text-sm text-slate-500">Estamos abriendo tu conexión a Internet…</p>
            <form name="hotspot" method="post" action="{{ $loginUrl }}" class="mt-5">
                <input type="hidden" name="username" value="{{ $username }}">
                <input type="hidden" name="password" value="{{ $password }}">
                @if ($destination)<input type="hidden" name="dst" value="{{ $destination }}">@endif
                <input type="hidden" name="popup" value="false">
                <button type="submit" style="background-color: {{ $brand }}" class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">Continuar</button>
            </form>
            <script>setTimeout(function () { document.hotspot.submit(); }, 400);</script>
        @else
            <p class="mt-1 text-sm text-slate-500">Tu acceso fue validado.</p>
            <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
                Esta página no se abrió desde el WiFi del hotspot, así que no hay una conexión que activar.
                Conéctate a la red WiFi de {{ $tenant->name }} e inténtalo de nuevo.
            </p>
        @endif
    </div>
</body>
</html>
