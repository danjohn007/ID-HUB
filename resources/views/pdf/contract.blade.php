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
                @php $sig = $client->attachments()->where('kind', 'firma')->latest()->first(); @endphp
                @if ($sig && is_file(storage_path('app/public/'.$sig->path)))<img src="{{ storage_path('app/public/'.$sig->path) }}" style="height: 50px"><br>@endif
                ______________________________<br>{{ $client->full_name }}<br><span class="muted">EL CLIENTE</span>
            </td>
            <td class="center">______________________________<br>{{ $tenant->legal_name ?: $tenant->name }}<br><span class="muted">EL PROVEEDOR</span></td>
        </tr>
    </table>
@endsection
