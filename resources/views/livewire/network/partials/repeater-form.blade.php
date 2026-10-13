{{-- Modal alta/edición de repetidor (trait ManagesRepeaters) --}}
<x-ui.modal wire:model="showRepeaterForm" :title="$repeaterId ? 'Editar repetidor' : 'Nuevo repetidor'" size="4xl">
    <form wire:submit="saveRepeater" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <x-ui.input label="ID" wire:model="rform.code" filter="upper" maxlength="30" required placeholder="R-19" />
            <x-ui.input label="Nombre" wire:model="rform.name" filter="text" maxlength="150" required class="md:col-span-2" />
            <x-ui.select label="Torre" wire:model.live="rform.tower_id" :options="$towers" placeholder="—" />
            <x-ui.select label="Zona" wire:model="rform.zone_id" :options="$zones" placeholder="—" />
            <x-ui.input label="Ubicación" wire:model="rform.location" filter="text" maxlength="190" placeholder="Cerro, azotea, calle..." class="md:col-span-3" />
        </div>
        <div>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-ui.input label="Latitud" wire:model.blur="rform.lat" filter="signed" />
                <x-ui.input label="Longitud" wire:model.blur="rform.lng" filter="signed" />
                <x-ui.input label="Radio de cobertura" type="number" step="0.1" min="0.1" max="100" wire:model="rform.coverage_radius_km" suffix="km" required />
                <x-ui.input label="Ancho de banda" type="number" step="1" min="0" max="100000" wire:model="rform.bandwidth_mbps" suffix="Mbps" />
            </div>
            <p class="mt-2 text-xs text-slate-500">Haz clic en el mapa o arrastra el marcador para fijar la ubicación.</p>
            <div wire:key="rpick-{{ $pickerVersion }}">
                <x-ui.map :id="'rpick'.$pickerVersion" picker picker-lat="rform.lat" picker-lng="rform.lng"
                          :value="! empty($rform['lat']) && ! empty($rform['lng']) ? ['lat' => (float) $rform['lat'], 'lng' => (float) $rform['lng']] : null"
                          :center="! empty($rform['lat']) && ! empty($rform['lng']) ? [(float) $rform['lat'], (float) $rform['lng']] : null"
                          :zoom="! empty($rform['lat']) ? 15 : 12" height="260px" class="mt-2" :layers-control="false" />
            </div>
        </div>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <x-ui.input label="Capacidad" type="number" min="1" max="100000" wire:model="rform.capacity" suffix="clientes" required />
            <x-ui.input label="Umbral de alerta" type="number" min="10" max="100" wire:model="rform.alert_threshold" suffix="%" required />
            <x-ui.select label="MikroTik (router)" wire:model="rform.router_device_id" :options="$routerOptions" placeholder="—" />
            <x-ui.select label="Equipo AP" wire:model="rform.ap_device_id" :options="$apOptions" placeholder="—" />
            <x-ui.select label="Técnico responsable" wire:model="rform.technician_id" :options="$technicians" placeholder="—" class="md:col-span-2" />
            <x-ui.input label="Costo mensual" type="number" step="0.01" min="0" max="10000000" wire:model="rform.monthly_cost" prefix="$" />
        </div>
        <x-ui.textarea label="Notas" wire:model="rform.notes" rows="2" maxlength="2000" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
        <x-ui.button icon="check" wire:click="saveRepeater">Guardar</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
