<div>
    <x-ui.page-header title="Planes SaaS" subtitle="Suscripciones que contratan los WISP: límites, funciones y comisión por pagos en línea">
        <x-ui.button icon="plus" wire:click="create">Nuevo plan</x-ui.button>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($plans as $p)
            <x-ui.card wire:key="p{{ $p->id }}" :class="$p->active ? '' : 'opacity-60'">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-navy-900">{{ $p->name }}</h3>
                            @unless ($p->active)<x-ui.badge size="xs">Inactivo</x-ui.badge>@endunless
                        </div>
                        <div class="font-mono text-xs text-slate-400">{{ $p->code }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold text-navy-900">{{ money($p->price_monthly, false) }}</div>
                        <div class="text-xs text-slate-400">por mes</div>
                    </div>
                </div>
                @if ($p->description)<p class="mt-2 text-sm text-slate-500">{{ $p->description }}</p>@endif

                <dl class="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center">
                    <div><dt class="text-xs text-slate-500">Clientes</dt><dd class="font-semibold text-slate-800">{{ $p->max_clients ? number_format($p->max_clients) : '∞' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Equipos</dt><dd class="font-semibold text-slate-800">{{ $p->max_devices ? number_format($p->max_devices) : '∞' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Usuarios</dt><dd class="font-semibold text-slate-800">{{ $p->max_users ? number_format($p->max_users) : '∞' }}</dd></div>
                </dl>

                <div class="mt-3 flex flex-wrap gap-1">
                    @if (in_array('*', $p->features ?? []))
                        <x-ui.badge color="purple" size="xs">Todas las funciones</x-ui.badge>
                    @else
                        @foreach ($p->features ?? [] as $f)
                            <x-ui.badge color="brand" size="xs">{{ $features[$f] ?? $f }}</x-ui.badge>
                        @endforeach
                    @endif
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
                    <span>Comisión: <b class="text-slate-700">{{ (float) $p->commission_percent }}%{{ (float) $p->commission_fixed ? ' + '.money($p->commission_fixed) : '' }}</b></span>
                    <span><b class="text-slate-700">{{ $p->tenants_count }}</b> WISP</span>
                </div>
                <div class="mt-3 flex justify-end gap-1">
                    <x-ui.button variant="ghost" size="sm" :icon="$p->active ? 'pause-circle' : 'play-circle'" wire:click="toggleActive({{ $p->id }})">{{ $p->active ? 'Desactivar' : 'Activar' }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" icon="pencil-square" wire:click="edit({{ $p->id }})">Editar</x-ui.button>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card class="md:col-span-2 xl:col-span-3">
                <p class="py-6 text-center text-sm text-slate-500">Aún no hay planes SaaS. Crea el primero.</p>
            </x-ui.card>
        @endforelse
    </div>

    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar plan SaaS' : 'Nuevo plan SaaS'" size="2xl">
        <form wire:submit="save" id="saas-plan-form" class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <x-ui.input label="Nombre" wire:model="form.name" required class="sm:col-span-3" />
            <x-ui.input label="Código" wire:model="form.code" hint="Ej. PRO" required class="sm:col-span-2" />
            <x-ui.input label="Orden" type="number" min="0" wire:model="form.sort" />
            <x-ui.input label="Descripción" wire:model="form.description" class="sm:col-span-6" />
            <x-ui.input label="Mensualidad" type="number" step="0.01" min="0" prefix="$" wire:model="form.price_monthly" required class="sm:col-span-2" />
            <x-ui.input label="Comisión %" type="number" step="0.01" min="0" max="100" suffix="%" wire:model="form.commission_percent" hint="Sobre cada pago en línea" required class="sm:col-span-2" />
            <x-ui.input label="Comisión fija" type="number" step="0.01" min="0" prefix="$" wire:model="form.commission_fixed" hint="Por pago en línea" required class="sm:col-span-2" />
            <x-ui.input label="Máx. clientes" type="number" min="1" wire:model="form.max_clients" hint="Vacío = ilimitado" class="sm:col-span-2" />
            <x-ui.input label="Máx. equipos" type="number" min="1" wire:model="form.max_devices" hint="Vacío = ilimitado" class="sm:col-span-2" />
            <x-ui.input label="Máx. usuarios" type="number" min="1" wire:model="form.max_users" hint="Vacío = ilimitado" class="sm:col-span-2" />

            <div class="sm:col-span-6">
                <label class="form-label">Funciones incluidas</label>
                <div class="grid grid-cols-2 gap-1 rounded-lg border border-slate-200 p-2 sm:grid-cols-3">
                    <label class="flex items-center gap-2 rounded px-2 py-1 text-sm font-semibold hover:bg-slate-50">
                        <input type="checkbox" value="*" wire:model.live="form.features" class="rounded border-slate-300"> Todas
                    </label>
                    @foreach ($features as $key => $label)
                        <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50">
                            <input type="checkbox" value="{{ $key }}" wire:model="form.features" class="rounded border-slate-300" @disabled(in_array('*', $form['features'] ?? []))> {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <x-ui.toggle wire:model="form.active" label="Plan activo" description="Los planes inactivos no se ofrecen a WISP nuevos" class="sm:col-span-6" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="saas-plan-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
