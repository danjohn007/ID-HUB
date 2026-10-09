<div>
    <x-ui.page-header title="Empresas WISP" subtitle="Uso de cada WISP contra los límites de su plan, vigencia y adeudo">
        <x-ui.button variant="secondary" icon="globe-americas" href="{{ route('saas.dashboard') }}">Panel SaaS</x-ui.button>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar por nombre, identificador o correo..." class="lg:flex-1" />
            <div class="grid grid-cols-2 gap-3 lg:flex lg:w-auto">
                <x-ui.select wire:model.live="filterStatus" :options="$statuses" placeholder="Todos los estados" />
                <x-ui.select wire:model.live="filterPlan" :options="$plans" placeholder="Todos los planes" />
            </div>
            <x-ui.toggle wire:model.live="onlyAtLimit" :label="'En el límite ('.$atLimitCount.')'" class="shrink-0" />
        </div>
        <x-ui.table>
            <x-slot:head>
                <th>WISP</th>
                <th>Plan</th>
                <th class="min-w-56">Uso del plan</th>
                <th class="text-right">Equipos online</th>
                <th>Suscripción</th>
                <th class="text-right">Adeudo SaaS</th>
                <th></th>
            </x-slot:head>
            @forelse ($rows as $row)
                @php
                    $t = $row->tenant;
                    $nDevices = (int) ($devices[$t->id] ?? 0);
                    $owed = $debt[$t->id] ?? null;
                    $ends = $t->status === 'trial' ? $t->trial_ends_at : $t->subscription_ends_at;
                @endphp
                <tr wire:key="t{{ $t->id }}" @class(['opacity-60' => ! $t->isActive()])>
                    <td>
                        <a href="{{ route('saas.tenants.show', $t) }}" wire:navigate class="flex items-center gap-3 hover:text-brand-600">
                            <x-ui.avatar :name="$t->name" />
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800">{{ $t->name }}</div>
                                <div class="text-xs text-slate-500">{{ $t->slug }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <x-ui.badge :color="['active' => 'success', 'trial' => 'brand', 'suspended' => 'danger', 'cancelled' => 'slate'][$t->status] ?? 'slate'" dot>
                                        {{ $statuses[$t->status] ?? $t->status }}
                                    </x-ui.badge>
                                    @if ($row->atLimit)<x-ui.badge color="warning">En el límite</x-ui.badge>@endif
                                </div>
                            </div>
                        </a>
                    </td>
                    <td class="text-sm text-slate-600">{{ $t->saasPlan?->name ?? 'Sin plan' }}@if ($t->saasPlan)<div class="text-xs text-slate-400">{{ money($t->saasPlan->price_monthly, false) }}/mes</div>@endif</td>
                    <td>
                        <div class="space-y-1.5">
                            @foreach ($row->limits as $l)
                                <div class="text-xs">
                                    <div class="flex justify-between gap-2 text-slate-500">
                                        <span>{{ $l['label'] }}</span>
                                        <span @class(['font-semibold', 'text-danger-600' => $l['max'] && $l['used'] >= $l['max'], 'text-slate-700' => ! $l['max'] || $l['used'] < $l['max']])>
                                            {{ number_format($l['used']) }} / {{ $l['max'] ? number_format($l['max']) : '∞' }}
                                        </span>
                                    </div>
                                    @if ($l['max'])<x-ui.progress :value="$l['pct']" height="h-1.5" class="mt-0.5" />@endif
                                </div>
                            @endforeach
                        </div>
                    </td>
                    <td class="text-right text-sm">
                        <span class="font-semibold text-slate-800">{{ number_format($online[$t->id] ?? 0) }}</span><span class="text-slate-400"> / {{ number_format($nDevices) }}</span>
                        <div class="text-xs text-slate-400">{{ $nDevices ? pct(($online[$t->id] ?? 0) / $nDevices * 100, 0) : '—' }}</div>
                    </td>
                    <td class="text-sm">
                        @if ($ends)
                            <span @class(['font-medium', 'text-danger-600' => $ends->isPast(), 'text-slate-700' => ! $ends->isPast()])>{{ $ends->format('d/m/Y') }}</span>
                            <div class="text-xs text-slate-400">{{ $t->status === 'trial' ? 'Fin de prueba' : 'Vigente hasta' }}{{ $ends->isPast() ? ' · vencida' : '' }}</div>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="text-right text-sm">
                        @if ($owed)
                            <span class="font-semibold text-danger-600">{{ money($owed->total) }}</span>
                            <div class="text-xs text-slate-400">{{ $owed->n }} {{ $owed->n == 1 ? 'factura' : 'facturas' }}</div>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="text-right whitespace-nowrap">
                        <x-ui.button variant="secondary" size="sm" icon="arrow-right" wire:click="enter({{ $t->id }})">Operar</x-ui.button>
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="building-office" title="Sin empresas WISP" description="No hay WISP con estos filtros." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
