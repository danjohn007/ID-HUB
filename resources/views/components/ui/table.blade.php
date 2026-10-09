{{--
    <x-ui.table>
        <x-slot:head><th>Nombre</th>...</x-slot:head>
        @foreach (...) <tr><td>..</td></tr> @endforeach
    </x-ui.table>
    Usa <x-ui.th sort="campo" :sort-by="$sortBy" :sort-dir="$sortDir"> para columnas ordenables (wire:click="sort('campo')").
--}}
@props(['head' => null, 'footer' => null])
<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }}>
    <table class="table-base">
        @if ($head)
            <thead><tr>{{ $head }}</tr></thead>
        @endif
        <tbody class="divide-y divide-slate-100">
            {{ $slot }}
        </tbody>
        @if ($footer)
            <tfoot class="border-t border-slate-200 bg-slate-50/60 font-semibold">{{ $footer }}</tfoot>
        @endif
    </table>
</div>
