{{-- Layout base para PDF (dompdf: CSS 2.1, sin Tailwind). Uso: @extends('pdf.layout') @section('content') ... --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Documento' }}</title>
    <style>
        @page { margin: 28px 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; }
        .header { border-bottom: 3px solid #1A7FF5; padding-bottom: 10px; margin-bottom: 14px; }
        .header table { width: 100%; }
        .brand { font-size: 18px; font-weight: bold; color: #0B1B3A; }
        .brand span { color: #1A7FF5; }
        .muted { color: #64748b; }
        .right { text-align: right; }
        .center { text-align: center; }
        h1 { font-size: 16px; color: #0B1B3A; margin: 0 0 4px; }
        h2 { font-size: 12.5px; color: #0B1B3A; margin: 14px 0 6px; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th { background: #0B1B3A; color: #fff; text-align: left; padding: 5px 6px; font-size: 9.5px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; font-size: 9.5px; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; }
        .total { font-size: 14px; font-weight: bold; color: #0B1B3A; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #eef6ff; color: #1366d1; font-size: 9px; font-weight: bold; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 8.5px; color: #94a3b8; text-align: center; }
        .kv td { padding: 2px 8px 2px 0; vertical-align: top; }
    </style>
</head>
<body>
    @php $t = $tenant ?? tenant(); @endphp
    <div class="header">
        <table>
            <tr>
                <td>
                    @if ($t?->logo_path && is_file(storage_path('app/public/'.$t->logo_path)))
                        <img src="{{ storage_path('app/public/'.$t->logo_path) }}" style="max-height: 42px">
                    @else
                        <div class="brand">{{ $t?->name ?? 'WISP' }}<span>.</span></div>
                    @endif
                    <div class="muted">{{ $t?->legal_name }} @if ($t?->rfc) · RFC {{ $t->rfc }} @endif</div>
                    <div class="muted">{{ $t?->address }} @if ($t?->phone) · Tel. {{ $t->phone }} @endif</div>
                </td>
                <td class="right" style="vertical-align: top">
                    <h1>{{ $title ?? '' }}</h1>
                    <div class="muted">Generado {{ now()->format('d/m/Y H:i') }}</div>
                </td>
            </tr>
        </table>
    </div>
    @yield('content')
    <div class="footer">{{ $t?->name }} · Documento generado por WISPCONTROL</div>
</body>
</html>
