{{-- Modal alta/edición de MikroTik (trait ManagesRouters) --}}
<x-ui.modal wire:model="showRouterForm" :title="$routerId ? 'Editar MikroTik' : 'Registrar MikroTik'" size="3xl">
    <form wire:submit="saveRouter" class="space-y-5">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-ui.input label="Nombre" wire:model="mform.name" filter="text" maxlength="120" required placeholder="MK-NORTE-1" />
            <x-ui.input label="Modelo" wire:model="mform.model" filter="text" maxlength="100" placeholder="CCR2004-1G-12S+2XS" />
            <x-ui.input label="IP de gestión" wire:model="mform.ip" filter="ip" placeholder="10.10.1.1" />
            <x-ui.input label="Ubicación" wire:model="mform.location" filter="text" maxlength="190" class="md:col-span-2" />
            <x-ui.select label="Torre" wire:model="mform.tower_id" :options="$towerOptions" placeholder="—" />
        </div>

        <div class="rounded-xl border border-slate-200 p-4">
            <div class="mb-3 text-xs font-bold tracking-wide text-slate-500 uppercase">Conexión</div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-ui.select label="Modo de conexión" wire:model.live="mform.api_mode" :options="config('wisp.api_modes')" required class="md:col-span-2" />
                <x-ui.select label="Método de servicio" wire:model="mform.service_method" :options="config('wisp.service_methods')" required />
                @if (in_array($mform['api_mode'] ?? '', ['api', 'rest']))
                    <x-ui.input label="Host" wire:model="mform.api_host" filter="host" placeholder="Vacío = IP de gestión" />
                    <x-ui.input label="Puerto" type="number" min="1" max="65535" wire:model="mform.api_port" :placeholder="($mform['api_mode'] ?? '') === 'rest' ? (! empty($mform['api_ssl']) ? '443' : '80') : (! empty($mform['api_ssl']) ? '8729' : '8728')" />
                    <div class="flex items-end pb-2"><x-ui.toggle wire:model.live="mform.api_ssl" label="Usar SSL / HTTPS" /></div>
                    <x-ui.input label="Usuario" wire:model="mform.api_user" filter="username" maxlength="100" autocomplete="off" />
                    <x-ui.input label="Contraseña" type="password" wire:model="mform.password" maxlength="190" autocomplete="new-password"
                                :hint="$routerHasPassword ? 'Guardada (cifrada). Deja vacío para conservarla.' : 'Se guarda cifrada.'" />
                @endif
                <x-ui.input label="Address-list de suspendidos" wire:model="mform.suspended_list" filter="code" maxlength="60" placeholder="WISP_MOROSOS" />
            </div>
            @if (($mform['api_mode'] ?? '') === 'push')
                <div class="mt-3 flex gap-2 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-800"><x-heroicon-o-information-circle class="h-4 w-4 shrink-0" />
                    El MikroTik se conecta al sistema (ideal para hosting compartido). Al guardar, abre la pestaña «Script de agente» y pega el script en la terminal del equipo.</div>
            @elseif (($mform['api_mode'] ?? '') === 'simulated')
                <div class="mt-3 flex gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"><x-heroicon-o-beaker class="h-4 w-4 shrink-0" />
                    Modo de demostración: genera datos realistas a partir de los clientes registrados y no se conecta a ningún equipo.</div>
            @else
                <div class="mt-3 flex gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"><x-heroicon-o-shield-check class="h-4 w-4 shrink-0" />
                    Habilita el servicio en el equipo (<code>/ip service</code> {{ ($mform['api_mode'] ?? '') === 'rest' ? 'www / www-ssl' : 'api / api-ssl' }}) y restringe el acceso a la IP del servidor. Usa un usuario con permisos read, write, api.</div>
            @endif
        </div>
        <x-ui.textarea label="Notas" wire:model="mform.notes" rows="2" maxlength="2000" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
        <x-ui.button icon="check" wire:click="saveRouter">Guardar</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
