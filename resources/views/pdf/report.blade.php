@extends('pdf.layout')

@section('content')
    @if ($summary)
        <table style="width: 100%; margin-bottom: 8px"><tr>
            @foreach ($summary as $label => $value)
                <td class="box" style="width: {{ 100 / count($summary) }}%"><div class="muted">{{ $label }}</div><div class="total">{{ $value }}</div></td>
            @endforeach
        </tr></table>
    @endif
    {{-- dompdf consume mucha memoria con una sola tabla grande: se parte en bloques con el encabezado repetido --}}
    @foreach (array_chunk($data['rows'], 40) as $chunk)
        <table class="data">
            <thead><tr>@foreach ($data['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($chunk as $row)
                    <tr @if ($loop->odd) class="alt" @endif>@foreach ($row as $cell)<td>{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    <p class="muted" style="margin-top: 8px">
        {{ number_format(count($data['rows'])) }} registros
        @if (($total ?? 0) > count($data['rows'])) de {{ number_format($total) }} · el PDF muestra los primeros {{ number_format(count($data['rows'])) }}; exporta a Excel o CSV para el listado completo @endif
    </p>
@endsection
