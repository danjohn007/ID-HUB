<div>
    <x-ui.page-header title="MikroTik" subtitle="Equipos MikroTik registrados, conexión API y control de clientes">
        <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar por nombre o IP..." class="w-56" />
        <x-ui.button icon="plus" wire:click="newRouter">Registrar MikroTik</x-ui.button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($routers as $d)
            @php $s = $stats[$d->id] ?? ['total' => 0, 'online' => 0]; @endphp
            <div wire:key="mk{{ $d->id }}" class="card flex flex-col">
                <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-4">
                    <a href="{{ route('network.mikrotik.show', $d) }}" wire:navigate class="flex min-w-0 items-center gap-3">
                        <span @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-xl', 'bg-success-50 text-success-600' => $d->status === 'online', 'bg-warning-50 text-warning-600' => $d->status === 'warning', 'bg-danger-50 text-danger-600' => $d->status === 'offline', 'bg-slate-100 text-slate-500' => ! in_array($d->status, ['online', 'warning', 'offline'])])>
                            <x-heroicon-o-cpu-chip class="h-6 w-6" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate font-bold text-navy-900 hover:text-brand-600">{{ $d->name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ $d->model ?: 'MikroTik' }} · {{ $d->api_host ?: $d->ip ?: 'sin IP' }}{{ $d->api_port ? ':'.$d->api_port : '' }}</span>
                        </span>
                    </a>
                    <x-ui.status catalog="device_status" :value="$d->status" size="xs" />
                </div>
                <div class="grid grid-cols-3 gap-2 p-4 text-center">
                    <div><div class="text-[11px] text-slate-500">CPU</div><div class="text-lg font-bold {{ ($d->cpu ?? 0) >= 85 ? 'text-danger-600' : 'text-navy-900' }}">{{ $d->cpu !== null ? round($d->cpu).'%' : '—' }}</div></div>
                    <div><div class="text-[11px] text-slate-500">Memoria</div><div class="text-lg font-bold text-navy-900">{{ $d->memory !== null ? round($d->memory).'%' : '—' }}</div></div>
                    <div><div class="text-[11px] text-slate-500">Clientes</div><div class="text-lg font-bold text-navy-900">{{ $s['online'] }}<span class="text-xs font-medium text-slate-400">/{{ $s['total'] }}</span></div></div>
                </div>
                <div class="flex flex-wrap gap-1.5 px-4 pb-3 text-xs">
                    <x-ui.badge :color="['api' => 'brand', 'rest' => 'teal', 'push' => 'purple', 'simulated' => 'slate'][$d->api_mode] ?? 'slate'" size="xs">{{ \Illuminate\Support\Str::before(config('wisp.api_modes.'.$d->api_mode, 'Simulador'), ' (') }}</x-ui.badge>
                    <x-ui.badge color="slate" size="xs">{{ config('wisp.service_methods.'.$d->service_method, 'PPPoE') }}</x-ui.badge>
                    <x-ui.badge color="slate" size="xs">{{ $repeaterCounts[$d->id] ?? 0 }} repetidores</x-ui.badge>
                    @if ($d->api_mode === 'push' && ($pending[$d->id] ?? 0) > 0)
                        <x-ui.badge color="warning" size="xs">{{ $pending[$d->id] }} comandos en cola</x-ui.badge>
                    @endif
                </div>
                @if ($d->last_error)
                    <div class="mx-4 mb-3 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700">{{ $d->last_error }}</div>
                @endif
                <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5">
                    <span class="text-[11px] text-slate-500">{{ $d->last_sync_at ? 'Sincronizado '.$d->last_sync_at->diffForHumans() : 'Sin sincronizar' }}</span>
                    <span class="flex gap-1">
                        <x-ui.button size="sm" variant="ghost" icon="signal" wire:click="testRouter({{ $d->id }})" title="Probar conexión" />
                        <x-ui.button size="sm" variant="ghost" icon="pencil-square" wire:click="editRouter({{ $d->id }})" title="Editar" />
                        <x-ui.button size="sm" variant="secondary" icon="arrow-right" :href="route('network.mikrotik.show', $d)">Abrir</x-ui.button>
                    </span>
                </div>
            </div>
        @empty
            <div class="card md:col-span-2 xl:col-span-3">
                <x-ui.empty icon="cpu-chip" title="Sin equipos MikroTik" description="Registra tu primer MikroTik para consultar clientes, tráfico y aplicar cortes y reconexiones automáticas.">
                    <x-ui.button icon="plus" wire:click="newRouter">Registrar MikroTik</x-ui.button>
                </x-ui.empty>
            </div>
        @endforelse
    </div>

    <x-ui.card class="mt-5" title="Modos de conexión">
        <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-4">
            <div><div class="font-semibold text-navy-900">API RouterOS</div><p class="text-xs text-slate-500">Conexión directa por los puertos 8728/8729. Requiere que el servidor alcance al equipo.</p></div>
            <div><div class="font-semibold text-navy-900">REST API</div><p class="text-xs text-slate-500">RouterOS v7 vía HTTPS (www-ssl). Misma condición de alcance.</p></div>
            <div><div class="font-semibold text-navy-900">Agente push</div><p class="text-xs text-slate-500">El MikroTik envía su telemetría y descarga comandos cada 1–5 min. Ideal para hosting compartido o equipos detrás de NAT.</p></div>
            <div><div class="font-semibold text-navy-900">Simulador</div><p class="text-xs text-slate-500">Datos de demostración generados con tus clientes; útil para capacitación.</p></div>
        </div>
    </x-ui.card>

    @include('livewire.network.partials.router-form')
</div>
