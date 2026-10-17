<div>
    <x-ui.modal wire:model="show" :title="$meetingId ? 'Editar demo' : 'Agendar demo'" size="xl">
        <form wire:submit="save" id="meeting-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($lead)
                <div class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2.5 sm:col-span-2">
                    <x-ui.avatar :name="$lead->name" size="sm" />
                    <div class="min-w-0 text-sm">
                        <div class="font-semibold text-slate-800">{{ $lead->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ $lead->company ?: 'Sin nombre de WISP' }}{{ $lead->email ? ' · '.$lead->email : '' }}</div>
                    </div>
                </div>
            @else
                <x-ui.select label="Prospecto" wire:model="form.lead_id" :options="$leads" placeholder="Selecciona..." required class="sm:col-span-2" />
            @endif
            <x-ui.input label="Fecha" type="date" wire:model="form.date" required />
            <div class="grid grid-cols-2 gap-3">
                <x-ui.input label="Hora" type="time" step="900" wire:model="form.time" required />
                <x-ui.select label="Duración" wire:model="form.duration" :options="['30' => '30 min', '45' => '45 min', '60' => '1 hora', '90' => '1.5 horas']" required />
            </div>
            <x-ui.select label="Responsable" wire:model="form.assigned_to" :options="$users" placeholder="Sin asignar" />
            <x-ui.input label="Enlace de videollamada" type="url" wire:model="form.meeting_url" placeholder="https://meet.google.com/..." maxlength="255" />
            <x-ui.textarea label="Notas internas" wire:model="form.notes" rows="2" maxlength="1000" class="sm:col-span-2" placeholder="Qué le interesa ver, quién más estará en la llamada..." />
            <x-ui.toggle wire:model="form.notify" label="Enviar confirmación al prospecto" description="Correo con fecha, hora, enlace e invitación para su calendario (.ics)." class="sm:col-span-2" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="meeting-form" icon="calendar-days">{{ $meetingId ? 'Guardar cambios' : 'Agendar' }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
