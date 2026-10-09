@extends('pdf.layout')

@section('content')
    <table style="width: 100%"><tr>
        <td><div class="muted">Folio</div><div class="total">{{ $payment->folio }}</div></td>
        <td class="right"><div class="muted">Fecha</div><b>{{ $payment->paid_at->format('d/m/Y H:i') }}</b></td>
    </tr></table>
    <div class="box" style="margin-top: 10px">
        <div class="muted">Recibimos de</div>
        <b>{{ $payment->client->full_name }}</b> · Cliente #{{ $payment->client->code }}<br>
        {{ $payment->client->address }}
    </div>
    <table class="data" style="margin-top: 10px">
        <thead><tr><th>Concepto</th><th class="right">Importe</th></tr></thead>
        <tbody>
            @forelse ($payment->allocations as $a)
                <tr><td>{{ $a->charge?->description }}</td><td class="right">{{ money($a->amount) }}</td></tr>
            @empty
                <tr><td>{{ $payment->notes ?: 'Abono a cuenta' }}</td><td class="right">{{ money($payment->amount) }}</td></tr>
            @endforelse
            @php $excess = (float) $payment->amount - (float) $payment->allocations->sum('amount'); @endphp
            @if ($payment->allocations->isNotEmpty() && $excess > 0.009)
                <tr><td>Saldo a favor</td><td class="right">{{ money($excess) }}</td></tr>
            @endif
        </tbody>
    </table>
    <table style="width: 100%; margin-top: 10px"><tr>
        <td class="muted">Método: {{ $payment->methodLabel() }} @if ($payment->reference)· Ref. {{ $payment->reference }}@endif<br>
            Recibió: {{ $payment->collector?->name ?? $payment->receivedBy?->name ?? 'Sistema' }}</td>
        <td class="right"><div class="muted">Total</div><div class="total">{{ money($payment->amount) }}</div></td>
    </tr></table>
    <p class="muted center" style="margin-top: 16px">Saldo pendiente después de este pago: {{ money($payment->client->balance) }}<br>¡Gracias por su pago!</p>
@endsection
