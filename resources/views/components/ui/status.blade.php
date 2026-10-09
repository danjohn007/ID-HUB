{{-- Badge a partir de un catálogo de config/wisp.php:  <x-ui.status catalog="client_status" :value="$client->service_status" /> --}}
@props(['catalog', 'value', 'dot' => true, 'size' => 'sm'])
<x-ui.badge :color="\App\Support\Catalog::color($catalog, $value)" :dot="$dot" :size="$size" {{ $attributes }}>
    {{ \App\Support\Catalog::label($catalog, $value) }}
</x-ui.badge>
