{{-- Par etiqueta/valor para fichas de detalle: <x-ui.detail label="Teléfono">{{ $client->phone }}</x-ui.detail> --}}
@props(['label'])
<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
    <dd class="mt-0.5 text-sm font-medium text-slate-800 [overflow-wrap:anywhere]">{{ trim($slot) !== '' ? $slot : '—' }}</dd>
</div>
