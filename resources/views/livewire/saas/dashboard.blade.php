<div>
    <x-ui.page-header title="Panel SaaS" subtitle="Empresas WISP de la plataforma, consumo y comisiones">
        <x-ui.button icon="plus" wire:click="create">Nuevo WISP</x-ui.button>
    </x-ui.page-header>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Empresas WISP" :value="number_format($kpi['tenants'])" icon="building-office" color="navy" :sub="$kpi['tenants_active'].' activas'" />
        <x-ui.stat label="Clientes" :value="number_format($kpi['clients'])" icon="users" color="brand" :sub="number_format($kpi['clients_active']).' activos'" />
        <x-ui.stat label="Equipos" :value="number_format($kpi['devices'])" icon="server-stack" color="teal" :sub="($kpi['devices'] ? pct($kpi['online'] / $kpi['devices'] * 100, 0) : '0%').' online'" />
        <x-ui.stat label="MRR plataforma" :value="money($kpi['mrr'], false)" icon="banknotes" color="success" :sub="'Comisiones del mes '.money($kpi['commissions'])" />
    </div>

    @if ($sales)
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat variant="soft" label="Demos de hoy" :value="number_format($sales['today'])" icon="calendar-days" color="purple" :href="route('saas.sales.agenda', ['vista' => 'lista'])" />
            <x-ui.stat variant="soft" label="Prospectos nuevos" :value="number_format($sales['new'])" icon="sparkles" color="brand" :href="route('saas.sales.leads')" />
            <x-ui.stat variant="soft" label="Seguimientos vencidos" :value="number_format($sales['overdue'])" icon="clock" :color="$sales['overdue'] ? 'danger' : 'success'" :href="route('saas.sales.leads', ['vencidos' => 1])" />
            <x-ui.stat variant="soft" label="Pruebas por vencer (5 días)" :value="number_format($sales['trials'])" icon="clock" :color="$sales['trials'] ? 'warning' : 'teal'" :href="route('saas.tenants.index', ['estado' => 'trial'])" />
        </div>
    @endif

    <x-ui.card :padding="false">
        <div class="border-b border-slate-100 p-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Buscar WISP por nombre o identificador..." />
        </div>
        <x-ui.table>
            <x-slot:head>
                <th>WISP</th>
                <th>Plan</th>
                <th class="text-right">Clientes</th>
                <th class="text-right">Equipos</th>
                <th class="text-right">Pagos en línea (mes)</th>
                <th>Estado</th>
                <th></th>
            </x-slot:head>
            @forelse ($tenants as $t)
                @php
                    $plan = $t->saasPlan;
                    $nClients = (int) ($stats['clients'][$t->id] ?? 0);
                    $pay = $stats['payments'][$t->id] ?? null;
                @endphp
                <tr wire:key="t{{ $t->id }}" @class(['opacity-60' => ! $t->isActive()])>
                    <td>
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$t->name" />
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800">{{ $t->name }}</div>
                                <div class="text-xs text-slate-500">{{ $t->slug }}{{ $t->email ? ' · '.$t->email : '' }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="text-sm text-slate-600">{{ $plan?->name ?? 'Sin plan' }}@if ($plan)<div class="text-xs text-slate-400">{{ money($plan->price_monthly, false) }}/mes</div>@endif</td>
                    <td class="text-right text-sm">
                        <span class="font-semibold text-slate-800">{{ number_format($nClients) }}</span>@if ($plan?->max_clients)<span class="text-slate-400"> / {{ number_format($plan->max_clients) }}</span>@endif
                        <div class="text-xs text-slate-400">{{ number_format($stats['activeClients'][$t->id] ?? 0) }} activos</div>
                    </td>
                    <td class="text-right text-sm">
                        <span class="font-semibold text-slate-800">{{ number_format($stats['devices'][$t->id] ?? 0) }}</span>
                        <div class="text-xs text-slate-400">{{ number_format($stats['online'][$t->id] ?? 0) }} online</div>
                    </td>
                    <td class="text-right text-sm">
                        <span class="font-semibold text-slate-800">{{ money($pay->amount ?? 0) }}</span>
                        <div class="text-xs text-slate-400">Comisión {{ money($pay->platform_fee ?? 0) }}</div>
                    </td>
                    <td>
                        <x-ui.badge :color="['active' => 'success', 'trial' => 'brand', 'suspended' => 'danger', 'cancelled' => 'slate'][$t->status] ?? 'slate'" dot>
                            {{ ['active' => 'Activo', 'trial' => 'Prueba', 'suspended' => 'Suspendido', 'cancelled' => 'Cancelado'][$t->status] ?? $t->status }}
                        </x-ui.badge>
                    </td>
                    <td class="text-right whitespace-nowrap">
                        <x-ui.button variant="secondary" size="sm" icon="arrow-right" wire:click="enter({{ $t->id }})">Operar</x-ui.button>
                        <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editTenant({{ $t->id }})" title="Editar" />
                        @if ($t->isActive())
                            <x-ui.button variant="ghost" size="sm" icon="no-symbol" class="text-danger-600" wire:click="toggleStatus({{ $t->id }})" wire:confirm="¿Suspender {{ $t->name }}? Sus usuarios no podrán iniciar sesión." title="Suspender" />
                        @else
                            <x-ui.button variant="ghost" size="sm" icon="check-circle" class="text-success-600" wire:click="toggleStatus({{ $t->id }})" title="Reactivar" />
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="building-office" title="Sin empresas WISP" description="Crea el primer WISP para empezar a operar." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Alta de WISP --}}
    <x-ui.modal wire:model="showForm" title="Nuevo WISP" size="2xl">
        {{-- El identificador se arma en el navegador a partir del nombre (sin ir al servidor en cada tecla)
             y deja de seguirlo en cuanto el usuario lo escribe a mano. --}}
        @if ($leadName)
            <div class="mb-4 flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2.5 text-sm text-brand-800">
                <x-heroicon-m-user-group class="h-4 w-4 shrink-0" /> <span>Datos tomados del prospecto <strong>{{ $leadName }}</strong>. Solo falta la contraseña del administrador.</span>
            </div>
        @endif
        <form wire:submit="save" id="tenant-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" wire:key="tenant-form-{{ $formKey }}"
              x-data="{ slugTouched: false, slugify: (v) => v.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) }">
            <x-ui.input label="Nombre comercial" wire:model="form.name" filter="text" maxlength="120" required class="sm:col-span-2"
                        x-on:input="if (! slugTouched) { $refs.slug.value = slugify($event.target.value); $refs.slug.dispatchEvent(new Event('input')) }" />
            <x-ui.input label="Identificador" wire:model="form.slug" filter="slug" maxlength="80" hint="Solo letras, números y guiones; también se usa como subdominio" required
                        x-ref="slug" x-on:input="if ($event.isTrusted) slugTouched = $event.target.value !== ''" />
            <x-ui.select label="Plan SaaS" wire:model="form.saas_plan_id" :options="$plans" placeholder="Sin plan" />
            <x-ui.input label="Correo de contacto" type="email" wire:model="form.email" filter="email" />
            <x-ui.input label="Teléfono / WhatsApp" type="tel" wire:model="form.phone" filter="phone" />

            <div class="sm:col-span-2 border-t border-slate-100 pt-4 text-sm font-semibold text-navy-900">Administrador del WISP</div>
            <x-ui.input label="Nombre" wire:model="form.admin_name" filter="name" maxlength="80" required />
            <x-ui.input label="Correo (usuario de acceso)" type="email" wire:model="form.admin_email" filter="email" required />
            <x-ui.input label="Contraseña" type="password" autocomplete="new-password" wire:model="form.admin_password" maxlength="255" hint="Mínimo 8 caracteres con letras y números" required class="sm:col-span-2" />

            <div class="flex flex-col gap-3 border-t border-slate-100 pt-4 sm:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                <x-ui.toggle wire:model.live="form.trial" label="Prueba gratuita" description="El WISP entra en periodo de prueba; al pasarlo a Activo se marca como ganado en Ventas." />
                @if (! empty($form['trial']))
                    <x-ui.input type="number" min="1" max="90" wire:model="form.trial_days" suffix="días" class="w-36" aria-label="Días de prueba" />
                @endif
            </div>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="tenant-form" icon="check">Crear WISP</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Edición de WISP --}}
    <x-ui.modal wire:model="showEdit" title="Editar WISP" size="2xl">
        <form wire:submit="updateTenant" id="tenant-edit-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Nombre comercial" wire:model="edit.name" filter="text" maxlength="120" required class="sm:col-span-2" />
            <x-ui.input label="Correo de contacto" type="email" wire:model="edit.email" filter="email" />
            <x-ui.input label="Teléfono / WhatsApp" type="tel" wire:model="edit.phone" filter="phone" />
            <x-ui.select label="Plan SaaS" wire:model="edit.saas_plan_id" :options="$plans" placeholder="Sin plan" />
            <x-ui.select label="Estado" wire:model.live="edit.status" :options="\App\Livewire\Saas\Dashboard::STATUSES" required />
            <x-ui.input label="Fin del periodo de prueba" type="date" wire:model="edit.trial_ends_at" />
            <x-ui.input label="Suscripción vigente hasta" type="date" wire:model="edit.subscription_ends_at" />
            @if (($edit['status'] ?? '') === 'suspended')
                <x-ui.input label="Motivo de suspensión" wire:model="edit.suspended_reason" maxlength="190" class="sm:col-span-2" />
            @endif
            <p class="sm:col-span-2 text-xs text-slate-500">Con estado Suspendido o Cancelado, los usuarios del WISP no pueden iniciar sesión.</p>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="tenant-edit-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
