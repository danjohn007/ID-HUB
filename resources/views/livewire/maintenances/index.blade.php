<div>
    <x-ui.page-header title="Mantenimientos" subtitle="Ventanas de mantenimiento programado y aviso anticipado a clientes">
        <x-ui.button icon="plus" wire:click="create">Programar mantenimiento</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="En curso" :value="$counts['in_progress'] ?? 0" icon="wrench-screwdriver" color="warning" variant="soft" />
        <x-ui.stat label="Programados" :value="$counts['scheduled'] ?? 0" icon="calendar-days" color="brand" variant="soft" :sub="$upcoming.' en los próximos 7 días'" />
        <x-ui.stat label="Completados" :value="$counts['completed'] ?? 0" icon="check-circle" color="success" variant="soft" />
        <x-ui.stat label="Cancelados" :value="$counts['cancelled'] ?? 0" icon="x-circle" color="slate" variant="soft" />
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar por título..." class="md:col-span-3" />
            <x-ui.select wire:model.live="filterStatus" :options="collect(\App\Models\Maintenance::STATUS)->map(fn ($s) => $s[0])->all()" placeholder="Todos los estados" />
        </div>
        <x-ui.table>
            <x-slot:head>
                <th>Mantenimiento</th>
                <th>Ventana</th>
                <th>Alcance</th>
                <th class="text-right">Clientes</th>
                <th>Aviso</th>
                <th>Estado</th>
                <th></th>
            </x-slot:head>
            @forelse ($maintenances as $m)
                @php [$label, $color] = \App\Models\Maintenance::STATUS[$m->status] ?? [$m->status, 'slate']; @endphp
                <tr wire:key="m{{ $m->id }}" @class(['opacity-60' => in_array($m->status, ['completed', 'cancelled'])])>
                    <td>
                        <div class="font-semibold text-slate-800">{{ $m->title }}</div>
                        @if ($m->description)<div class="max-w-xs truncate text-xs text-slate-500">{{ $m->description }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap text-sm text-slate-600">
                        {{ $m->starts_at->format('d/m/Y H:i') }}
                        @if ($m->ends_at)<div class="text-xs text-slate-400">hasta {{ $m->ends_at->isSameDay($m->starts_at) ? $m->ends_at->format('H:i') : $m->ends_at->format('d/m/Y H:i') }}</div>@endif
                    </td>
                    <td class="text-sm text-slate-600">
                        @if ($m->zone)<div>{{ $m->zone->name }}</div>@endif
                        @if ($m->repeater)
                            <a href="{{ route('network.repeaters.show', $m->repeater) }}" wire:navigate class="link text-xs">{{ $m->repeater->code }} · {{ $m->repeater->name }}</a>
                        @endif
                        @if ($m->device_ids)<div class="text-xs text-slate-400">{{ count($m->device_ids) }} equipo(s)</div>@endif
                    </td>
                    <td class="text-right font-semibold text-slate-800">{{ number_format($m->affected_clients) }}</td>
                    <td class="text-xs">
                        @if ($m->notified_at)
                            <x-ui.badge color="success" size="xs">Enviado {{ $m->notified_at->format('d/m H:i') }}</x-ui.badge>
                        @elseif ($m->status === 'scheduled')
                            <span class="text-slate-500">{{ $m->notify_hours_before }} h antes</span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td><x-ui.badge :color="$color" dot>{{ $label }}</x-ui.badge></td>
                    <td class="whitespace-nowrap text-right">
                        @if (in_array($m->status, ['scheduled', 'in_progress']))
                            <x-ui.button variant="ghost" size="sm" icon="megaphone" wire:click="notifyNow({{ $m->id }})" wire:confirm="¿Enviar el aviso de «{{ $m->title }}» a los clientes afectados ahora?" title="Avisar ahora" />
                        @endif
                        @if ($m->status === 'scheduled')
                            <x-ui.button variant="ghost" size="sm" icon="play" wire:click="setStatus({{ $m->id }}, 'in_progress')" title="Iniciar" />
                        @endif
                        @if ($m->status === 'in_progress')
                            <x-ui.button variant="ghost" size="sm" icon="check-circle" class="text-success-600" wire:click="setStatus({{ $m->id }}, 'completed')" title="Completar" />
                        @endif
                        @if (! in_array($m->status, ['completed', 'cancelled']))
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $m->id }})" title="Editar" />
                        @endif
                        @if ($m->status === 'scheduled')
                            <x-ui.button variant="ghost" size="sm" icon="no-symbol" class="text-danger-600" wire:click="setStatus({{ $m->id }}, 'cancelled')" wire:confirm="¿Cancelar «{{ $m->title }}»?" title="Cancelar" />
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="calendar-days" title="Sin mantenimientos" description="Programa una ventana para avisar con anticipación a los clientes afectados." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $maintenances->links() }}</div>
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar mantenimiento' : 'Programar mantenimiento'" size="2xl">
        <form wire:submit="save" id="maintenance-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Título" wire:model="form.title" placeholder="Cambio de antena sectorial" required class="sm:col-span-2" />
            <x-ui.input label="Inicio" type="datetime-local" wire:model="form.starts_at" required />
            <x-ui.input label="Fin estimado" type="datetime-local" wire:model="form.ends_at" />
            <x-ui.select label="Zona" wire:model.live="form.zone_id" :options="$zones" placeholder="Sin zona" />
            <x-ui.select label="Repetidor" wire:model.live="form.repeater_id" :options="$repeaters" placeholder="Sin repetidor" />

            @if ($devices->isNotEmpty())
                <div class="sm:col-span-2">
                    <label class="form-label">Equipos afectados {{ ($form['repeater_id'] ?? '') ? 'del repetidor' : '' }}</label>
                    <div class="max-h-40 overflow-y-auto rounded-lg border border-slate-200 p-2">
                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                            @foreach ($devices as $d)
                                <label wire:key="dev{{ $d->id }}" class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50">
                                    <input type="checkbox" value="{{ $d->id }}" wire:model.live="form.device_ids" class="rounded border-slate-300">
                                    <span class="truncate text-slate-700">{{ $d->name }}</span>
                                    <span class="text-xs text-slate-400">{{ config('wisp.device_types.'.$d->type, $d->type) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            <x-ui.textarea label="Descripción (se incluye en el aviso)" wire:model="form.description" rows="2" class="sm:col-span-2" />
            <x-ui.select label="Aviso automático" wire:model="form.notify_hours_before" :options="['0' => 'Sin aviso automático', '2' => '2 horas antes', '6' => '6 horas antes', '12' => '12 horas antes', '24' => '24 horas antes', '48' => '48 horas antes', '72' => '72 horas antes']" />
            <div class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2">
                <x-heroicon-o-users class="h-5 w-5 text-slate-400" />
                <div class="text-sm"><b class="text-navy-900">{{ number_format($affectedPreview) }}</b> <span class="text-slate-500">clientes potencialmente afectados</span></div>
            </div>
            @error('form.zone_id')<p class="sm:col-span-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            @if ($editingId)
                <x-ui.button variant="ghost" class="mr-auto text-danger-600" icon="trash" wire:click="delete({{ $editingId }})" wire:confirm="¿Eliminar este mantenimiento?">Eliminar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="maintenance-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
