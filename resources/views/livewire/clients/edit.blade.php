<div>
    <x-ui.page-header :title="$client ? 'Editar cliente #'.$client->code : 'Nuevo cliente'"
                      :breadcrumbs="['Clientes' => route('clients.index'), ($client ? $client->full_name : 'Nuevo') => null]">
        <x-ui.button variant="secondary" :href="$client ? route('clients.show', $client) : route('clients.index')">Cancelar</x-ui.button>
        <x-ui.button wire:click="save" icon="check">Guardar</x-ui.button>
    </x-ui.page-header>

    <form wire:submit="save" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card title="Datos del cliente">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-ui.input label="ID de cliente" wire:model="form.code" filter="code" required />
                    <x-ui.input label="Nombre" wire:model.blur="form.first_name" filter="name" required />
                    <x-ui.input label="Apellidos" wire:model="form.last_name" filter="name" />
                    <x-ui.input label="Teléfono" wire:model="form.phone" type="tel" filter="phone" />
                    <x-ui.input label="WhatsApp" wire:model="form.whatsapp" type="tel" filter="phone" hint="Se usa para avisos automáticos" />
                    <x-ui.input label="Correo electrónico" wire:model="form.email" type="email" filter="email" />
                    <x-ui.input label="CURP (opcional)" wire:model="form.curp" filter="curp" />
                    <x-ui.input label="RFC (opcional)" wire:model="form.rfc" filter="rfc" />
                    <x-ui.select label="Estado del servicio" wire:model="form.service_status" :options="config('wisp.client_status')" required />
                </div>
            </x-ui.card>

            <x-ui.card title="Ubicación" subtitle="Haz clic en el mapa para fijar las coordenadas GPS">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-ui.input label="Dirección" wire:model="form.address" filter="text" maxlength="190" class="md:col-span-2" />
                    <x-ui.input label="Referencias" wire:model="form.references" filter="text" maxlength="190" />
                    <x-ui.select label="Zona" wire:model="form.zone_id" :options="$zones" placeholder="—" />
                    <x-ui.input label="Comunidad" wire:model="form.community" filter="text" maxlength="120" />
                    <x-ui.input label="Colonia" wire:model="form.colonia" filter="text" maxlength="120" />
                    <x-ui.input label="Latitud" wire:model.live.debounce.600ms="form.lat" filter="signed" />
                    <x-ui.input label="Longitud" wire:model.live.debounce.600ms="form.lng" filter="signed" />
                    <div class="flex items-end" x-data="geo('form.lat', 'form.lng', 'fillAddressFromLocation')">
                        <x-ui.button variant="secondary" icon="map-pin" @click="locate()" class="w-full">
                            <span x-show="!locating">Usar mi ubicación</span><span x-show="locating">Ubicando...</span>
                        </x-ui.button>
                    </div>
                </div>
                @if ($coverageMessage)
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800"><x-heroicon-o-signal class="h-4 w-4" />{{ $coverageMessage }}</div>
                @endif
                <x-ui.map id="client-picker" picker picker-lat="form.lat" picker-lng="form.lng" :value="$form['lat'] ? ['lat' => (float) $form['lat'], 'lng' => (float) $form['lng']] : null"
                          :center="$form['lat'] ? [(float) $form['lat'], (float) $form['lng']] : null" :zoom="$form['lat'] ? 16 : 13" height="320px" class="mt-4" :layers-control="false" />
            </x-ui.card>

            <x-ui.card title="Red y equipo">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <x-ui.select label="Repetidor asociado" wire:model.live="form.repeater_id" :options="$repeaters" placeholder="—" class="md:col-span-2" />
                    <x-ui.select label="MikroTik" wire:model="form.router_device_id" :options="$routers" placeholder="—" />
                    <x-ui.select label="Antena / CPE" wire:model="form.cpe_device_id" :options="$cpes" placeholder="—" />
                    <x-ui.input label="IP" wire:model="form.ip_address" filter="ip" placeholder="10.100.0.25" />
                    <x-ui.input label="MAC Address" wire:model="form.mac_address" filter="mac" placeholder="AA:BB:CC:DD:EE:FF" />
                    <x-ui.input label="Usuario PPPoE" wire:model="form.pppoe_user" filter="username" maxlength="80" />
                    <x-ui.input label="Contraseña PPPoE" wire:model="form.pppoe_password" maxlength="80" />
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card title="Servicio">
                <div class="space-y-4">
                    <x-ui.select label="Plan contratado" wire:model="form.plan_id" :options="$plans" placeholder="Selecciona un plan" required />
                    <x-ui.input label="Fecha de instalación" type="date" wire:model="form.installation_date" />
                    <x-ui.input label="Día de facturación" type="number" min="1" max="28" wire:model="form.billing_day" hint="Aplica si el WISP factura por aniversario" />
                    <x-ui.select label="Técnico responsable" wire:model="form.technician_id" :options="$technicians" placeholder="—" />
                    <x-ui.select label="Cobrador asignado" wire:model="form.collector_id" :options="$collectors" placeholder="—" />
                </div>
            </x-ui.card>

            @unless ($client)
                <x-ui.card title="Al registrar">
                    <div class="space-y-3">
                        <x-ui.toggle wire:model="activateNow" label="Activar servicio en MikroTik" description="Crea el usuario PPPoE / cola con la velocidad del plan" />
                        <x-ui.toggle wire:model="chargeInstallation" label="Generar cargo de instalación" description="Según la tarifa de instalación del plan" />
                        <x-ui.input label="Código de referido (opcional)" wire:model="referralCode" filter="upper" placeholder="INVITA-5821" />
                    </div>
                </x-ui.card>
            @endunless

            <x-ui.card title="Notas">
                <x-ui.textarea wire:model="form.notes" rows="4" maxlength="2000" />
            </x-ui.card>

            <x-ui.button type="submit" icon="check" class="w-full py-2.5">Guardar cliente</x-ui.button>
        </div>
    </form>
</div>
