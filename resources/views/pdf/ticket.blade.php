@extends('pdf.layout')

@section('content')
    <table class="kv" style="margin-bottom: 8px">
        <tr><td class="muted">Folio</td><td><b>{{ $ticket->folio }}</b></td><td class="muted">Categoría</td><td>{{ config('wisp.ticket_categories.'.$ticket->category) }}</td></tr>
        <tr><td class="muted">Cliente</td><td>{{ $ticket->client?->full_name ?? 'Infraestructura' }}</td><td class="muted">Prioridad</td><td>{{ \App\Support\Catalog::label('ticket_priorities', $ticket->priority) }}</td></tr>
        <tr><td class="muted">Técnico</td><td>{{ $ticket->technician?->name }}</td><td class="muted">Estado</td><td>{{ \App\Support\Catalog::label('ticket_status', $ticket->status) }}</td></tr>
        <tr><td class="muted">Llegada</td><td>{{ $ticket->arrived_at?->format('d/m/Y H:i') }}</td><td class="muted">Salida</td><td>{{ $ticket->departed_at?->format('d/m/Y H:i') }}</td></tr>
    </table>
    <h2>{{ $ticket->subject }}</h2>
    <p>{{ $ticket->description }}</p>
    @if ($ticket->resolution)<h2>Resolución</h2><p>{{ $ticket->resolution }}</p>@endif
    @if ($ticket->materials->isNotEmpty())
        <h2>Materiales utilizados</h2>
        <table class="data"><thead><tr><th>Artículo</th><th class="right">Cantidad</th></tr></thead>
            @foreach ($ticket->materials as $m)<tr><td>{{ $m->item?->name }}</td><td class="right">{{ abs($m->quantity) }}</td></tr>@endforeach</table>
    @endif
    @php $photos = $ticket->attachments->filter(fn ($a) => $a->isImage() && is_file(storage_path('app/public/'.$a->path))); @endphp
    @if ($photos->isNotEmpty())
        <h2>Evidencia fotográfica</h2>
        <table style="width: 100%"><tr>
            @foreach ($photos->take(6) as $i => $a)
                <td style="width: 33%; padding: 4px; vertical-align: top"><img src="{{ storage_path('app/public/'.$a->path) }}" style="width: 100%; max-height: 140px"><br><span class="muted">{{ config('wisp.evidence_kinds.'.$a->kind) }} · {{ $a->taken_at?->format('d/m/Y H:i') }} @if ($a->lat)· GPS {{ round($a->lat, 5) }}, {{ round($a->lng, 5) }}@endif</span></td>
                @if (($i + 1) % 3 === 0)</tr><tr>@endif
            @endforeach
        </tr></table>
    @endif
    <h2>Historial</h2>
    <table class="data">
        @foreach ($ticket->comments->sortBy('created_at') as $c)
            <tr><td style="width: 22%">{{ $c->created_at->format('d/m/Y H:i') }}</td><td style="width: 20%">{{ $c->user?->name ?? 'Sistema' }}</td><td>{{ $c->body }}</td></tr>
        @endforeach
    </table>
    @if ($ticket->signature_path && is_file(storage_path('app/public/'.$ticket->signature_path)))
        <div style="margin-top: 30px" class="center"><img src="{{ storage_path('app/public/'.$ticket->signature_path) }}" style="height: 50px"><br>Firma de conformidad del cliente</div>
    @endif
@endsection
