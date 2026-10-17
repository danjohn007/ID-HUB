{{-- Motivo de pérdida (trait HandlesStages) --}}
<x-ui.modal wire:model="showLost" title="Marcar como perdido" size="md">
    <form wire:submit="confirmLost" id="lost-form" class="space-y-4">
        <x-ui.select label="Motivo" wire:model="lost.reason" :options="\App\Models\DemoRequest::LOST_REASONS" placeholder="Selecciona..." required />
        <x-ui.textarea label="Comentario" wire:model="lost.note" rows="2" maxlength="300" placeholder="Opcional" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
        <x-ui.button type="submit" form="lost-form" variant="danger" icon="x-circle">Marcar perdido</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
