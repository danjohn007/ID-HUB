<div>
    <x-ui.page-header title="Planes de Internet" subtitle="Paquetes, precios, políticas de corte y reconexión">
        <x-ui.toggle wire:model.live="showInactive" label="Mostrar inactivos" />
        <x-ui.button icon="plus" wire:click="create">Nuevo plan</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat variant="soft" label="Planes activos" :value="$rows->where('plan.active', true)->count()" icon="rectangle-stack" color="brand" />
        <x-ui.stat variant="soft" label="Clientes con plan" :value="number_format($totalClients)" icon="users" color="success" />
        <x-ui.stat variant="soft" label="MRR por planes" :value="money($totalMrr, false)" icon="currency-dollar" color="purple" />
        <x-ui.stat variant="soft" label="ARPU" :value="money($totalClients ? $totalMrr / $totalClients : 0)" icon="calculator" color="teal" />
    </div>

    {{-- Tarjetas de precios --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @forelse ($rows as $r)
            @php $p = $r['plan']; $b = $p->priceBreakdown(); $promos = $promosByPlan($p->id); @endphp
            <div wire:key="card-{{ $p->id }}" @class(['card relative flex flex-col overflow-hidden', 'opacity-60' => ! $p->active])>
                <div class="h-1.5" style="background: {{ $p->color }}"></div>
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-lg font-bold text-navy-900">{{ $p->name }}</div>
                            <div class="text-xs text-slate-500">{{ $p->description ?: $p->periodicityLabel() }}</div>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            @if (! $p->active)<x-ui.badge color="slate" size="xs">Inactivo</x-ui.badge>@endif
                            @if ($p->active && $p->public)<x-ui.badge color="teal" size="xs">Público</x-ui.badge>@endif
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-1">
                        <span class="text-4xl font-extrabold tracking-tight" style="color: {{ $p->color }}">{{ (float) $p->download_mbps }}</span>
                        <span class="text-sm font-semibold text-slate-500">Mbps</span>
                        <span class="ml-auto text-xs text-slate-400">↑ {{ (float) $p->upload_mbps }} Mbps</span>
                    </div>
                    <div class="mt-2">
                        <span class="text-2xl font-bold text-navy-900">{{ money($b['total']) }}</span>
                        <span class="text-sm text-slate-500">/ {{ mb_strtolower($p->periodicityLabel()) }}</span>
                        <div class="text-[11px] text-slate-400">{{ $p->price_includes_tax ? 'IVA incluido' : 'Más IVA' }} ({{ (float) $p->tax_rate }}%) · subtotal {{ money($b['subtotal']) }}</div>
                    </div>
                    <ul class="mt-4 space-y-1.5 text-xs text-slate-600">
                        <li class="flex items-center gap-2"><x-heroicon-m-circle-stack class="h-4 w-4 text-slate-400" />{{ $p->data_cap_gb ? 'Límite '.number_format($p->data_cap_gb).' GB' : 'Sin límite de consumo' }}</li>
                        <li class="flex items-center gap-2"><x-heroicon-m-wrench class="h-4 w-4 text-slate-400" />Instalación {{ money($p->installation_fee) }}</li>
                        <li class="flex items-center gap-2"><x-heroicon-m-pause-circle class="h-4 w-4 text-slate-400" />Corte {{ $p->block_type ? ($p->block_type === 'partial' ? 'parcial' : 'total') : 'según política' }} · tolerancia {{ $p->grace_days ?? tenant()->setting('billing.grace_days') }} días</li>
                        <li class="flex items-center gap-2"><x-heroicon-m-arrow-path class="h-4 w-4 text-slate-400" />Reconexión {{ $p->auto_reconnect ? 'automática' : 'manual' }}{{ (float) $p->reconnection_fee > 0 ? ' · '.money($p->reconnection_fee) : '' }}</li>
                        @if ($p->mikrotik_profile)<li class="flex items-center gap-2"><x-heroicon-m-cpu-chip class="h-4 w-4 text-slate-400" />Perfil {{ $p->mikrotik_profile }}</li>@endif
                    </ul>
                    @if ($promos->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-1">
                            @foreach ($promos->take(2) as $pr)<x-ui.badge color="purple" size="xs">{{ $pr->name }}</x-ui.badge>@endforeach
                        </div>
                    @endif
                    <div class="mt-auto pt-4">
                        <div class="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-2.5 text-center">
                            <div><div class="text-lg font-bold text-navy-900">{{ number_format($r['billable']) }}</div><div class="text-[11px] text-slate-500">clientes</div></div>
                            <div><div class="text-lg font-bold text-navy-900">{{ money($r['mrr'], false) }}</div><div class="text-[11px] text-slate-500">MRR</div></div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <x-ui.button variant="secondary" size="sm" icon="pencil-square" class="flex-1" wire:click="edit({{ $p->id }})">Editar</x-ui.button>
                            <x-ui.dropdown>
                                <x-slot:trigger><x-ui.button variant="secondary" size="sm" icon="ellipsis-horizontal" /></x-slot:trigger>
                                <x-ui.dropdown-item icon="document-duplicate" wire:click="duplicate({{ $p->id }})">Duplicar</x-ui.dropdown-item>
                                <x-ui.dropdown-item :icon="$p->active ? 'pause' : 'play'" wire:click="toggle({{ $p->id }}, 'active')">{{ $p->active ? 'Desactivar' : 'Activar' }}</x-ui.dropdown-item>
                                <x-ui.dropdown-item :icon="$p->public ? 'eye-slash' : 'eye'" wire:click="toggle({{ $p->id }}, 'public')">{{ $p->public ? 'Ocultar del portal' : 'Mostrar en portal' }}</x-ui.dropdown-item>
                                <x-ui.dropdown-item icon="trash" danger wire:click="delete({{ $p->id }})" wire:confirm="¿Eliminar el plan? Si tiene clientes solo se desactivará.">Eliminar</x-ui.dropdown-item>
                            </x-ui.dropdown>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card col-span-full"><x-ui.empty icon="rectangle-stack" title="Sin planes" description="Crea tu primer plan de Internet." /></div>
        @endforelse
    </div>

    {{-- Tabla comparativa --}}
    <x-ui.card title="Detalle de planes" :padding="false">
        <x-ui.table>
            <x-slot:head>
                <th>Plan</th><th>Velocidad</th><th class="text-right">Precio</th><th>Periodicidad</th><th>Consumo</th><th>Corte / tolerancia</th>
                <th class="text-right">Recargo</th><th class="text-right">Reconexión</th><th class="text-right">Instalación</th><th>Equipo / perfil</th>
                <th class="text-right">Clientes</th><th class="text-right">MRR</th><th>Estado</th><th></th>
            </x-slot:head>
            @foreach ($rows as $r)
                @php $p = $r['plan']; @endphp
                <tr wire:key="row-{{ $p->id }}">
                    <td><span class="inline-flex items-center gap-2 font-semibold text-slate-800"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $p->color }}"></span>{{ $p->name }}</span></td>
                    <td class="text-xs whitespace-nowrap">↓ {{ (float) $p->download_mbps }} / ↑ {{ (float) $p->upload_mbps }} Mbps</td>
                    <td class="text-right font-semibold whitespace-nowrap">{{ money($p->price) }}<div class="text-[11px] font-normal text-slate-400">{{ $p->price_includes_tax ? 'IVA incl.' : '+IVA' }} {{ (float) $p->tax_rate }}%</div></td>
                    <td class="text-xs">{{ $p->periodicityLabel() }}</td>
                    <td class="text-xs">{{ $p->data_cap_gb ? number_format($p->data_cap_gb).' GB' : 'Ilimitado' }}</td>
                    <td class="text-xs">{{ $p->block_type ? ($p->block_type === 'partial' ? 'Parcial' : 'Total') : 'Política' }} · {{ $p->grace_days ?? 'def.' }} días</td>
                    <td class="text-right text-xs">{{ $p->late_fee !== null ? money($p->late_fee) : 'Política' }}</td>
                    <td class="text-right text-xs">{{ money($p->reconnection_fee) }}<div class="text-[11px] text-slate-400">{{ $p->auto_reconnect ? 'Automática' : 'Manual' }}</div></td>
                    <td class="text-right text-xs">{{ money($p->installation_fee) }}</td>
                    <td class="max-w-[160px] truncate text-xs text-slate-500">{{ $p->allowed_equipment ?: '—' }}<div>{{ $p->mikrotik_profile }}</div></td>
                    <td class="text-right font-semibold">{{ number_format($r['billable']) }}</td>
                    <td class="text-right font-semibold">{{ money($r['mrr'], false) }}</td>
                    <td>@if ($p->active)<x-ui.badge color="success" size="xs" dot>Activo</x-ui.badge>@else<x-ui.badge color="slate" size="xs" dot>Inactivo</x-ui.badge>@endif</td>
                    <td class="text-right"><x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $p->id }})" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar plan' : 'Nuevo plan'" size="3xl">
        <form wire:submit="save" id="plan-form" class="space-y-5">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <x-ui.input label="Nombre" wire:model="form.name" filter="text" maxlength="80" required />
                <x-ui.input label="Descripción" wire:model="form.description" maxlength="190" class="md:col-span-2" />
                <x-ui.input label="Descarga" type="number" step="0.1" min="0.1" max="100000" wire:model="form.download_mbps" suffix="Mbps" required />
                <x-ui.input label="Subida" type="number" step="0.1" min="0.1" max="100000" wire:model="form.upload_mbps" suffix="Mbps" required />
                <x-ui.select label="Periodicidad" wire:model="form.periodicity" :options="config('wisp.periodicity')" required />
                <x-ui.input label="Precio" type="number" step="0.01" min="0" max="1000000" wire:model="form.price" prefix="$" required />
                <x-ui.input label="IVA" type="number" step="0.01" min="0" max="100" wire:model="form.tax_rate" suffix="%" required />
                <div class="flex items-end pb-2"><x-ui.toggle wire:model="form.price_includes_tax" label="El precio incluye IVA" /></div>
            </div>
            <div class="border-t border-slate-100 pt-4">
                <div class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Políticas de suspensión y reconexión</div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <x-ui.input label="Límite de consumo" type="number" min="1" max="1000000" wire:model="form.data_cap_gb" suffix="GB" hint="Vacío = ilimitado" />
                    <x-ui.input label="Días de tolerancia" type="number" min="0" max="60" wire:model="form.grace_days" :hint="'Vacío = política del WISP ('.tenant()->setting('billing.grace_days').' días)'" />
                    <x-ui.select label="Tipo de bloqueo" wire:model="form.block_type" :options="['partial' => 'Parcial (velocidad reducida / aviso)', 'total' => 'Total']" placeholder="Política del WISP" />
                    <x-ui.input label="Recargo por atraso" type="number" step="0.01" min="0" max="100000" wire:model="form.late_fee" prefix="$" hint="Vacío = política del WISP" />
                    <x-ui.input label="Cargo de reconexión" type="number" step="0.01" min="0" max="100000" wire:model="form.reconnection_fee" prefix="$" />
                    <x-ui.input label="Costo de instalación" type="number" step="0.01" min="0" max="100000" wire:model="form.installation_fee" prefix="$" />
                    <div class="flex items-end pb-2 md:col-span-3"><x-ui.toggle wire:model="form.auto_reconnect" label="Reconexión automática al confirmarse el pago" /></div>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 md:grid-cols-2">
                <x-ui.input label="Equipo permitido" wire:model="form.allowed_equipment" filter="text" maxlength="190" placeholder="LiteBeam 5AC, TP-Link CPE510..." />
                <x-ui.input label="Perfil MikroTik (PPP profile / queue)" wire:model="form.mikrotik_profile" filter="code" maxlength="80" placeholder="plan-20M" />
                <div>
                    <label class="form-label">Color</label>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($colors as $c)
                            <button type="button" wire:click="$set('form.color', '{{ $c }}')" @class(['h-8 w-8 rounded-full ring-offset-2 transition', 'ring-2 ring-navy-900' => strtoupper($form['color']) === $c]) style="background: {{ $c }}"></button>
                        @endforeach
                        <input type="color" wire:model.live="form.color" class="h-8 w-10 cursor-pointer rounded border border-slate-300">
                    </div>
                </div>
                <div class="flex flex-col justify-end gap-2 pb-1">
                    <x-ui.toggle wire:model="form.active" label="Activo (disponible para contratar)" />
                    <x-ui.toggle wire:model="form.public" label="Público (visible en portal y página de referidos)" />
                </div>
            </div>
        </form>
        <x-slot:footer>
            @if ($editingId)
                <x-ui.button variant="ghost" class="mr-auto text-danger-600" icon="trash" wire:click="delete({{ $editingId }})" wire:confirm="¿Eliminar el plan? Si tiene clientes solo se desactivará.">Eliminar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="plan-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
