<x-ui.dropdown width="w-60">
    <x-slot:trigger><x-ui.button variant="ghost" size="sm" icon="ellipsis-vertical" /></x-slot:trigger>
    <x-ui.dropdown-item icon="pencil-square" wire:click="edit({{ $p->id }})">Editar</x-ui.dropdown-item>
    @if ($p->lat)
        <x-ui.dropdown-item icon="signal" wire:click="recheckCoverage({{ $p->id }})">Revalidar cobertura</x-ui.dropdown-item>
        <x-ui.dropdown-item icon="map" href="https://www.google.com/maps/dir/?api=1&destination={{ $p->lat }},{{ $p->lng }}" target="_blank">Cómo llegar</x-ui.dropdown-item>
    @endif
    @if ($p->status !== 'convertido')
        @can('installations.manage')
            <x-ui.dropdown-item icon="clipboard-document-check" wire:click="openOrder({{ $p->id }})">Crear cotización / orden</x-ui.dropdown-item>
        @endcan
        @can('clients.manage')
            <x-ui.dropdown-item icon="user-plus" wire:click="openConvert({{ $p->id }})">Convertir a cliente</x-ui.dropdown-item>
        @endcan
    @elseif ($p->client_id && Route::has('clients.show'))
        <x-ui.dropdown-item icon="user" :href="route('clients.show', $p->client_id)">Ver cliente</x-ui.dropdown-item>
    @endif
    <div class="my-1 border-t border-slate-100"></div>
    <div class="px-3 pt-1 pb-0.5 text-[10.5px] font-semibold tracking-wide text-slate-400 uppercase">Mover a</div>
    @foreach (['contactado', 'viable', 'sin_cobertura', 'instalacion_pendiente', 'descartado'] as $st)
        @if ($p->status !== $st)
            <x-ui.dropdown-item wire:click="setStatus({{ $p->id }}, '{{ $st }}')">{{ \App\Support\Catalog::label('prospect_status', $st) }}</x-ui.dropdown-item>
        @endif
    @endforeach
</x-ui.dropdown>
