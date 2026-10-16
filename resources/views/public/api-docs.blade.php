<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API v1 · {{ config('wisp.brand.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { --brand:#1A7FF5; --navy:#0B1B3A; --bg:#F5F7FB; --card:#fff; --text:#1E293B; --muted:#64748B; --line:#E2E8F0;
                --get:#0E9F6E; --post:#1A7FF5; --patch:#D97706; --put:#D97706; --delete:#DC2626; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
        header { background:linear-gradient(135deg, var(--navy), #13306b); color:#fff; padding:40px 16px 32px; }
        .wrap { max-width:1080px; margin:0 auto; padding:0 16px; }
        header .wrap { padding:0; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; letter-spacing:.04em; }
        .brand span.dot { width:12px; height:12px; border-radius:50%; background:var(--brand); box-shadow:0 0 0 4px rgba(26,127,245,.3); }
        h1 { margin:14px 0 6px; font-size:30px; }
        header p { margin:0; color:#cbd5e1; max-width:720px; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:13px; }
        .base { display:inline-block; margin-top:16px; background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2); padding:6px 12px; border-radius:8px; color:#fff; word-break:break-all; }
        main { padding:28px 0 60px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-bottom:28px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px; }
        .card h3 { margin:0 0 6px; font-size:15px; }
        .card p { margin:0; color:var(--muted); font-size:14px; }
        pre { background:#0f172a; color:#e2e8f0; padding:14px; border-radius:10px; overflow-x:auto; margin:10px 0 0; }
        section { background:var(--card); border:1px solid var(--line); border-radius:14px; margin-bottom:18px; overflow:hidden; }
        section > .head { padding:14px 18px; border-bottom:1px solid var(--line); display:flex; flex-wrap:wrap; justify-content:space-between; gap:6px 16px; align-items:baseline; }
        section h2 { margin:0; font-size:18px; }
        .pill { font-size:12px; color:var(--muted); background:#F1F5F9; border-radius:999px; padding:2px 10px; }
        .intro { padding:10px 18px 0; color:var(--muted); font-size:14px; margin:0; }
        .ep { display:grid; grid-template-columns:72px minmax(0, 300px) minmax(0, 1fr) auto; gap:12px; padding:10px 18px; border-top:1px solid #F1F5F9; align-items:start; font-size:14px; }
        .ep:first-of-type { border-top:0; }
        .m { font-weight:700; font-size:12px; color:#fff; border-radius:6px; padding:2px 0; text-align:center; }
        .m.GET { background:var(--get); } .m.POST { background:var(--post); } .m.PATCH, .m.PUT { background:var(--patch); } .m.DELETE { background:var(--delete); }
        .path { font-family: ui-monospace, Menlo, Consolas, monospace; font-size:13px; word-break:break-all; }
        .desc { color:#334155; }
        .perm { font-size:12px; color:#7c3aed; background:#F5F3FF; border-radius:6px; padding:2px 8px; white-space:nowrap; }
        footer { text-align:center; color:var(--muted); font-size:13px; padding:20px 16px 40px; }
        @media (max-width: 720px) {
            .ep { grid-template-columns:64px 1fr; }
            .ep .desc, .ep .perm-wrap { grid-column: 1 / -1; }
            h1 { font-size:24px; }
        }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <div class="brand"><span class="dot"></span>{{ config('wisp.brand.name') }}</div>
        <h1>API REST v1</h1>
        <p>API para apps móviles (técnico, cobrador y cliente) e integraciones (ERP, contabilidad, CRM, pasarelas, IoT). Respuestas JSON en español, paginación estándar y aislamiento total por WISP.</p>
        <div class="base">{{ $base }}</div>
    </div>
</header>

<main class="wrap">
    <div class="grid">
        <div class="card">
            <h3>Autenticación</h3>
            <p>Tokens Sanctum en el encabezado <code>Authorization: Bearer &lt;token&gt;</code>. Las integraciones usan <code>X-Api-Key</code>.</p>
        </div>
        <div class="card">
            <h3>Permisos</h3>
            <p>El token del personal hereda los permisos de su rol. El plan SaaS del WISP debe incluir <code>api</code>, <code>app_tecnico</code> o <code>app_cliente</code>.</p>
        </div>
        <div class="card">
            <h3>Errores y límites</h3>
            <p>JSON <code>{"message", "errors"}</code> con códigos 401, 403, 404, 422, 429. Límite: 120 solicitudes/min por token.</p>
        </div>
        <div class="card">
            <h3>Paginación</h3>
            <p>Listados con <code>data</code>, <code>links</code> y <code>meta</code>. Usa <code>page</code> y <code>per_page</code> (máx. 100).</p>
        </div>
    </div>

    <div class="card" style="margin-bottom:28px">
        <h3>Ejemplo rápido</h3>
        <pre>curl -X POST {{ $base }}/auth/login \
  -H "Accept: application/json" \
  -d "email=tecnico@empresa.com&amp;password=••••••&amp;device_name=Android"

curl {{ $base }}/field/today -H "Authorization: Bearer 12|AbCdEf..."</pre>
    </div>

    @foreach ($groups as $group)
        <section>
            <div class="head">
                <h2>{{ $group['title'] }}</h2>
                <span class="pill">{{ $group['auth'] }}</span>
            </div>
            @if (! empty($group['intro']))
                <p class="intro">{{ $group['intro'] }}</p>
            @endif
            <div>
                @foreach ($group['endpoints'] as [$method, $path, $desc, $perm])
                    <div class="ep">
                        <span class="m {{ $method }}">{{ $method }}</span>
                        <span class="path">/api/v1{{ $path }}</span>
                        <span class="desc">{{ $desc }}</span>
                        <span class="perm-wrap">@if ($perm)<span class="perm">{{ $perm }}</span>@endif</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</main>

<footer>{{ config('wisp.brand.name') }} · API v1 · Documentación detallada con ejemplos en <code>docs/API.md</code></footer>
</body>
</html>
