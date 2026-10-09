{{--
    Gráfica ECharts:  <x-ui.chart id="mrr" :options="$options" height="240px" />
    $options es un arreglo de opciones ECharts (xAxis, yAxis, series...). Paleta de marca aplicada por defecto.
    Actualizar desde Livewire:  $this->dispatch('chart-data-mrr', options: [...]);
--}}
@props(['id' => null, 'options' => [], 'height' => '260px'])
<div wire:ignore {{ $attributes }} x-data="chart(@js($options), @js($id))">
    <div x-ref="canvas" style="height: {{ $height }}; width: 100%"></div>
</div>
