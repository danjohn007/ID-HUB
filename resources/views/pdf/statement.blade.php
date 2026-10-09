@extends('pdf.layout')

@section('content')
    <table style="width: 100%; margin-bottom: 10px"><tr>
        <td class="box" style="width: 50%">
            <div class="muted">Cliente</div><b>{{ $client->full_name }}</b> · #{{ $client->code }}<br>
            {{ $client->address }}<br>Plan {{ $client->plan?->name }} {{ $client->plan?->speedLabel() }}
        </td>
        <td class="box right">
            <div class="muted">Saldo actual</div><div class="total">{{ money($client->balance) }}</div>
            @if ($client->credit_balance > 0)<div class="muted">Saldo a favor {{ money($client->credit_balance) }}</div>@endif
            <div class="muted">Próximo pago: {{ $client->next_due_date?->format('d/m/Y') }}</div>
        </td>
    </tr></table>

    <h2>Cargos (últimos 12 meses)</h2>
    <table class="data">
        <thead><tr><th>Folio</th><th>Concepto</th><th>Fecha límite</th><th class="right">Total</th><th class="right">Pagado</th><th>Estado</th></tr></thead>
        <tbody>
            @foreach ($charges as $c)
                <tr><td>{{ $c->folio }}</td><td>{{ $c->description }}</td><td>{{ $c->due_date->format('d/m/Y') }}</td>
                    <td class="right">{{ money($c->total) }}</td><td class="right">{{ money($c->paid_amount) }}</td><td>{{ \App\Support\Catalog::label('charge_status', $c->status) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <h2>Pagos (últimos 12 meses)</h2>
    <table class="data">
        <thead><tr><th>Folio</th><th>Fecha</th><th>Método</th><th>Referencia</th><th class="right">Importe</th></tr></thead>
        <tbody>
            @foreach ($payments as $p)
                <tr><td>{{ $p->folio }}</td><td>{{ $p->paid_at->format('d/m/Y') }}</td><td>{{ $p->methodLabel() }}</td><td>{{ $p->reference }}</td><td class="right">{{ money($p->amount) }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endsection
