@extends('pdf.layout')

@section('content')
    <table class="kv" style="margin-bottom: 8px">
        <tr><td class="muted">Cliente</td><td><b>{{ $order->customerName() }}</b></td><td class="muted">Estado</td><td>{{ \App\Support\Catalog::label('installation_status', $order->status) }}</td></tr>
        <tr><td class="muted">Domicilio</td><td>{{ $order->client?->address ?? $order->prospect?->address }}</td><td class="muted">Plan</td><td>{{ $order->plan?->name }} {{ $order->plan?->speedLabel() }}</td></tr>
        <tr><td class="muted">Técnico</td><td>{{ $order->technician?->name }}</td><td class="muted">Programada</td><td>{{ $order->scheduled_at?->format('d/m/Y H:i') }}</td></tr>
        <tr><td class="muted">Repetidor</td><td>{{ $order->repeater?->code }}</td><td class="muted">Cotización</td><td>{{ money($order->quote_amount) }}</td></tr>
    </table>
    <h2>Checklist de instalación</h2>
    <table class="data">
        @foreach (config('wisp.installation_checklist') as $key => $label)
            <tr><td>{{ $label }}</td><td class="right">{{ ($order->checklist[$key] ?? false) ? '✔ Realizado' : '— Pendiente' }}</td></tr>
        @endforeach
    </table>
    <h2>Mediciones</h2>
    <table class="kv">
        <tr><td class="muted">Señal</td><td>{{ $order->signal_dbm ? $order->signal_dbm.' dBm' : '—' }}</td><td class="muted">Bajada</td><td>{{ $order->speed_down ? $order->speed_down.' Mbps' : '—' }}</td><td class="muted">Subida</td><td>{{ $order->speed_up ? $order->speed_up.' Mbps' : '—' }}</td></tr>
    </table>
    @if ($order->notes)<h2>Notas</h2><p>{{ $order->notes }}</p>@endif
    <table style="width: 100%; margin-top: 50px"><tr>
        <td class="center" style="width: 50%">
            @if ($sigImg = \App\Support\Evidence::dataUri($order->signature_path))<img src="{{ $sigImg }}" style="height: 50px"><br>@endif
            ______________________________<br>{{ $order->signed_by_name ?? 'Firma del cliente' }}</td>
        <td class="center">______________________________<br>{{ $order->technician?->name ?? 'Técnico' }}</td>
    </tr></table>
@endsection
