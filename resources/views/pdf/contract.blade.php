@extends('pdf.layout', ['title' => 'Contrato de servicio'])

@section('content')
    <table class="kv" style="margin-bottom: 10px">
        <tr><td class="muted">Cliente</td><td><b>{{ $client->full_name }}</b> (#{{ $client->code }})</td><td class="muted">Plan</td><td>{{ $client->plan?->name }} · {{ $client->plan?->speedLabel() }}</td></tr>
        <tr><td class="muted">Domicilio</td><td>{{ $client->address }} {{ $client->colonia }}</td><td class="muted">Instalación</td><td>{{ $client->installation_date?->format('d/m/Y') }}</td></tr>
        <tr><td class="muted">Teléfono</td><td>{{ $client->phone }}</td><td class="muted">Usuario</td><td>{{ $client->pppoe_user }}</td></tr>
    </table>
    <div style="white-space: pre-line; line-height: 1.6; font-size: 11px; text-align: justify">{{ $body }}</div>
    <table style="width: 100%; margin-top: 70px">
        <tr>
            <td class="center" style="width: 50%">
                @php
                    // Firma del cliente: adjunto "firma" del cliente o, si no hay, la de su última orden de instalación
                    // (disco privado o público, embebida en base64).
                    $sig = $client->attachments()->where('kind', 'firma')->latest()->first();
                    $sigImg = $sig ? \App\Support\Evidence::dataUri($sig->path) : null;
                    $sigBy = null;
                    $sigAt = $sig?->taken_at ?? $sig?->created_at;
                    if (! $sigImg) {
                        $sigOrder = \App\Models\InstallationOrder::query()->where('client_id', $client->id)->whereNotNull('signature_path')->latest('id')->first();
                        $sigImg = $sigOrder ? \App\Support\Evidence::dataUri($sigOrder->signature_path) : null;
                        if ($sigImg) {
                            $sigBy = $sigOrder->signed_by_name;
                            $orderSig = $sigOrder->attachments()->where('kind', 'firma')->latest()->first();
                            $sigAt = $orderSig?->taken_at ?? $sigOrder->updated_at;
                        }
                    }
                @endphp
                @if ($sigImg)<img src="{{ $sigImg }}" style="height: 50px"><br>@endif
                ______________________________<br>{{ $client->full_name }}<br><span class="muted">EL CLIENTE</span>
                @if ($sigImg && ($sigBy || $sigAt))<br><span class="muted" style="font-size: 9px">Firmado{{ $sigBy ? ' por '.$sigBy : '' }}{{ $sigAt ? ' el '.$sigAt->format('d/m/Y H:i') : '' }}</span>@endif
            </td>
            <td class="center">______________________________<br>{{ $tenant->legal_name ?: $tenant->name }}<br><span class="muted">EL PROVEEDOR</span></td>
        </tr>
    </table>
@endsection
