@extends('pdf.layout')

@section('content')
    @if ($summary)
        <table style="width: 100%; margin-bottom: 8px"><tr>
            @foreach ($summary as $label => $value)
                <td class="box" style="width: {{ 100 / count($summary) }}%"><div class="muted">{{ $label }}</div><div class="total">{{ $value }}</div></td>
            @endforeach
        </tr></table>
    @endif
    <table class="data">
        <thead><tr>@foreach ($data['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach ($data['rows'] as $row)
                <tr>@foreach ($row as $cell)<td>{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
    <p class="muted" style="margin-top: 8px">{{ count($data['rows']) }} registros</p>
@endsection
