@php
    $m = $meeting;
    $titles = ['confirmation' => '¡Tu demo quedó agendada!', 'rescheduled' => 'Reprogramamos tu demo', 'reminder' => 'Te esperamos en tu demo'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $titles[$kind] ?? $titles['confirmation'] }}</title></head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#0f172a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden">
            <tr><td style="background:#0b1f4d;padding:24px 28px">
                <img src="{{ asset('images/brand/idhub-logo-dark.png') }}" alt="{{ $brand }}" height="32" style="display:block;height:32px;border:0">
                <h1 style="margin:18px 0 0;font-size:22px;line-height:1.3;color:#ffffff">{{ $titles[$kind] ?? $titles['confirmation'] }}</h1>
            </td></tr>
            <tr><td style="padding:24px 28px">
                <p style="margin:0 0 16px;font-size:15px;line-height:1.6">Hola {{ \Illuminate\Support\Str::of($lead->name)->before(' ') }},</p>
                <p style="margin:0 0 20px;font-size:15px;line-height:1.6">
                    @if ($kind === 'reminder')
                        Te recordamos tu demostración de {{ $brand }}. Te mostraremos cómo automatizar la cobranza, los cortes en MikroTik y el trabajo de campo de tu WISP.
                    @elseif ($kind === 'rescheduled')
                        Tu demostración de {{ $brand }} cambió de horario. Estos son los nuevos datos:
                    @else
                        Gracias por tu interés en {{ $brand }}. Estos son los datos de tu demostración:
                    @endif
                </p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f6fe;border-radius:12px">
                    <tr><td style="padding:16px 18px;font-size:14px;line-height:1.7">
                        <strong style="font-size:16px;color:#0b1f4d">{{ \Illuminate\Support\Str::ucfirst($m->starts_at->translatedFormat('l j \d\e F')) }}</strong><br>
                        {{ $m->starts_at->format('H:i') }} – {{ $m->endsAt()->format('H:i') }} h (hora del centro de México)<br>
                        Duración aproximada: {{ $m->duration_minutes }} minutos
                    </td></tr>
                </table>
                @if ($m->meeting_url)
                    <p style="margin:22px 0 0;text-align:center">
                        <a href="{{ $m->meeting_url }}" style="display:inline-block;background:#1a7ff5;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 26px;border-radius:10px">Entrar a la videollamada</a>
                    </p>
                @else
                    <p style="margin:18px 0 0;font-size:14px;line-height:1.6;color:#475569">Antes de la demo te compartiremos el enlace de la videollamada.</p>
                @endif
                <p style="margin:22px 0 0;font-size:14px;line-height:1.6;color:#475569">
                    Adjuntamos la invitación para que la agregues a tu calendario. Si necesitas cambiar el horario, responde a este correo.
                </p>
            </td></tr>
            <tr><td style="padding:16px 28px;background:#f8fafc;font-size:12px;color:#94a3b8;text-align:center">{{ $brand }} · {{ config('wisp.brand.tagline', 'Tu WISP bajo control') }}</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
