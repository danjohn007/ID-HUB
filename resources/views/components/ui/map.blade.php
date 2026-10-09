{{--
    Mapa Leaflet. Datos: markers [{lat,lng,type,status,color,layer,popup,url,pulse,tooltip}], circles, sectors, heat, lines.
    <x-ui.map id="ops" :markers="$markers" :sectors="$sectors" height="420px" fit cluster />
    Actualizar desde Livewire:  $this->dispatch('map-data-ops', data: ['markers' => [...]]);
    Selector de ubicación:      <x-ui.map id="pick" picker picker-lat="form.lat" picker-lng="form.lng" :value="['lat'=>..,'lng'=>..]" />
--}}
@props([
    'id' => 'map'.uniqid(), 'markers' => [], 'circles' => [], 'sectors' => [], 'heat' => [], 'lines' => [],
    'center' => null, 'zoom' => 13, 'height' => '400px', 'fit' => false, 'cluster' => false, 'picker' => false,
    'pickerLat' => 'lat', 'pickerLng' => 'lng', 'value' => null, 'satellite' => false, 'layersControl' => true,
])
@php
    $center ??= tenant()?->setting('map.center') ?? [20.5888, -100.3899];
    $config = compact('id', 'markers', 'circles', 'sectors', 'heat', 'lines', 'center', 'zoom', 'fit', 'cluster', 'picker', 'pickerLat', 'pickerLng', 'value', 'satellite', 'layersControl');
@endphp
<div wire:ignore {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-xl border border-slate-200']) }} style="height: {{ $height }}"
     x-data="mapView(@js($config))">
    <div x-ref="map" class="h-full w-full"></div>
</div>
