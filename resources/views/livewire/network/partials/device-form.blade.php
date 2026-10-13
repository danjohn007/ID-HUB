{{-- Modal alta/edición de equipo (trait ManagesDevices) --}}
<x-ui.modal wire:model="showDeviceForm" :title="$deviceId ? 'Editar equipo' : 'Nuevo equipo'" size="4xl">
    <form wire:submit="saveDevice" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <x-ui.select label="Tipo" wire:model.live="dform.type" :options="$deviceTypes" required />
            <x-ui.input label="Nombre" wire:model="dform.name" filter="text" maxlength="150" required class="md:col-span-2" placeholder="AP-NORTE-01, CPE-1025..." />
            <x-ui.select label="Estado" wire:model="dform.status" :options="config('wisp.device_status')" required />
            <x-ui.input label="Fabricante" wire:model="dform.manufacturer" filter="text" maxlength="100" placeholder="Ubiquiti, MikroTik, Cambium..." />
            <x-ui.input label="Modelo" wire:model="dform.model" filter="text" maxlength="100" />
            <x-ui.input label="Número de serie" wire:model="dform.serial" filter="code" maxlength="100" />
            <x-ui.input label="Firmware" wire:model="dform.firmware" filter="text" maxlength="100" />
            <x-ui.input label="MAC" wire:model="dform.mac" filter="mac" placeholder="AA:BB:CC:DD:EE:FF" />
            <x-ui.input label="IP" wire:model="dform.ip" filter="ip" placeholder="10.10.1.20" />
            <x-ui.input label="Fecha de instalación" type="date" wire:model="dform.installed_at" />
            <x-ui.select label="Responsable" wire:model="dform.responsible_id" :options="$responsibles" placeholder="—" />
        </div>
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="mb-3 text-xs font-bold tracking-wide text-slate-500 uppercase">Relación (Cliente → CPE → Repetidor → Torre)</div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-ui.input label="Cliente (código)" wire:model="dform.client_code" filter="code" placeholder="1025" hint="Para CPE / antenas de cliente" />
                <x-ui.select label="Repetidor" wire:model="dform.repeater_id" :options="$repeaterOptions" placeholder="—" />
                <x-ui.select label="Torre" wire:model="dform.tower_id" :options="$towerOptions" placeholder="—" />
                <x-ui.select label="Conectado a (equipo padre)" wire:model="dform.parent_id" :options="$parentOptions" placeholder="—" />
            </div>
        </div>
        <div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-ui.input label="Ubicación" wire:model="dform.location" filter="text" maxlength="190" class="md:col-span-2" />
                <x-ui.input label="Latitud" wire:model.blur="dform.lat" filter="signed" />
                <x-ui.input label="Longitud" wire:model.blur="dform.lng" filter="signed" />
            </div>
            <div wire:key="dpick-{{ $devicePickerVersion }}">
                <x-ui.map :id="'dpick'.$devicePickerVersion" picker picker-lat="dform.lat" picker-lng="dform.lng"
                          :value="! empty($dform['lat']) && ! empty($dform['lng']) ? ['lat' => (float) $dform['lat'], 'lng' => (float) $dform['lng']] : null"
                          :center="! empty($dform['lat']) && ! empty($dform['lng']) ? [(float) $dform['lat'], (float) $dform['lng']] : null"
                          :zoom="! empty($dform['lat']) ? 16 : 12" height="220px" class="mt-3" :layers-control="false" />
            </div>
        </div>
        @if (($dform['type'] ?? '') === 'mikrotik')
            <div class="flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800"><x-heroicon-o-information-circle class="h-4 w-4 shrink-0" />La conexión API (modo, usuario, contraseña) se configura en Red → MikroTik.</div>
        @endif
        <x-ui.textarea label="Notas" wire:model="dform.notes" rows="2" maxlength="2000" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
        <x-ui.button icon="check" wire:click="saveDevice">Guardar</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
