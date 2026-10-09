<div>
    <x-ui.page-header :title="$tenant->name" :subtitle="$tenant->legal_name ?: $tenant->slug" :breadcrumbs="['Empresas WISP' => route('saas.tenants.index'), $tenant->name => null]">
        <x-ui.badge :color="['active' => 'success', 'trial' => 'brand', 'suspended' => 'danger', 'cancelled' => 'slate'][$tenant->status] ?? 'slate'" dot>
            {{ $statuses[$tenant->status] ?? $tenant->status }}
        </x-ui.badge>
        <x-ui.button variant="secondary" icon="pencil-square" href="{{ route('saas.dashboard') }}">Editar en Panel SaaS</x-ui.button>
        <x-ui.button icon="arrow-right" wire:click="enter({{ $tenant->id }})">Operar WISP</x-ui.button>
    </x-ui.page-header>

    @if ($tenant->status === 'suspended' && $tenant->suspended_reason)
        <div class="mb-5 rounded-xl bg-danger-50 px-4 py-3 text-sm text-danger-700 ring-1 ring-danger-200/60">
            <span class="font-semibold">Suspendido:</span> {{ $tenant->suspended_reason }}
        </div>
    @endif

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-ui.stat label="Clientes" :value="number_format($clientsByStatus->sum())" icon="users" color="brand" :sub="number_format($clientsByStatus['activo'] ?? 0).' activos'" />
        <x-ui.stat label="Equipos de red" :value="number_format($devicesByStatus->sum())" icon="server-stack" color="teal"
                   :sub="($devicesByStatus->sum() ? pct(($devicesByStatus['online'] ?? 0) / $devicesByStatus->sum() * 100, 0) : '0%').' online'" />
        <x-ui.stat label="Pagos en línea (mes)" :value="money($month->amount ?? 0)" icon="credit-card" color="navy" :sub="number_format($month->n ?? 0).' pagos'" />
        <x-ui.stat label="Comisión del mes" :value="money($month->platform_fee ?? 0)" icon="banknotes" color="success" :sub="'Neto WISP '.money($month->net ?? 0)" />
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Plan y uso" :subtitle="$plan ? $plan->name.' · '.money($plan->price_monthly, false).'/mes' : 'Sin plan SaaS: sin límites y con todas las funciones'">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    @foreach ($limits as $l)
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-medium text-slate-600">{{ $l['label'] }}</span>
                                <span @class(['text-sm font-semibold', 'text-danger-600' => $l['max'] && $l['used'] >= $l['max'], 'text-slate-800' => ! $l['max'] || $l['used'] < $l['max']])>
                                    {{ number_format($l['used']) }} / {{ $l['max'] ? number_format($l['max']) : '∞' }}
                                </span>
                            </div>
                            @if ($l['max'])
                                <x-ui.progress :value="$l['pct']" class="mt-2" />
                            @else
                                <p class="mt-1 text-xs text-slate-400">Ilimitado</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 border-t border-slate-100 pt-4">
                    <div class="mb-2 text-xs font-medium text-slate-500">Funciones incluidas</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($features as $key => $label)
                            <x-ui.badge :color="$tenant->hasFeature($key) ? 'success' : 'slate'">
                                @if ($tenant->hasFeature($key))<x-heroicon-m-check class="h-3 w-3" />@else<x-heroicon-m-x-mark class="h-3 w-3" />@endif
                                {{ $label }}
                            </x-ui.badge>
                        @endforeach
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Pagos en línea y comisiones" subtitle="Últimos 12 meses">
                <x-ui.chart id="tenant-fees" :options="$chart" height="240px" />
            </x-ui.card>

            <x-ui.card title="Facturas SaaS" :padding="false">
                <x-slot:actions>
                    @if (Route::has('saas.commissions'))
                        <a href="{{ route('saas.commissions') }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:text-brand-700">Ver comisiones</a>
                    @endif
                </x-slot:actions>
                <x-ui.table>
                    <x-slot:head>
                        <th>Folio</th>
                        <th>Periodo</th>
                        <th class="text-right">Mensualidad</th>
                        <th class="text-right">Comisiones</th>
                        <th class="text-right">Total</th>
                        <th>Estado</th>
                    </x-slot:head>
                    @forelse ($invoices as $inv)
                        <tr wire:key="inv{{ $inv->id }}">
                            <td class="whitespace-nowrap font-mono text-xs text-slate-600">{{ $inv->folio }}</td>
                            <td class="whitespace-nowrap text-sm text-slate-600">{{ ucfirst($inv->period_start->translatedFormat('F Y')) }}</td>
                            <td class="text-right text-sm">{{ money($inv->amount) }}</td>
                            <td class="text-right text-sm">{{ money($inv->commissions) }}</td>
                            <td class="text-right text-sm font-semibold text-slate-800">{{ money($inv->total) }}</td>
                            <td>
                                <x-ui.badge :color="['pending' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'slate'][$inv->status] ?? 'slate'" dot>
                                    {{ ['pending' => 'Pendiente', 'paid' => 'Pagada', 'overdue' => 'Vencida', 'cancelled' => 'Cancelada'][$inv->status] ?? $inv->status }}
                                </x-ui.badge>
                                @if ($inv->paid_at)<div class="mt-0.5 text-xs text-slate-400">{{ $inv->paid_at->format('d/m/Y') }}</div>@endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="document-text" title="Sin facturas" description="Las facturas se generan cada mes desde Comisiones." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Usuarios" :subtitle="$users->where('active', true)->count().' activos de '.$users->count()" :padding="false">
                <x-ui.table>
                    <x-slot:head>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Último acceso</th>
                        <th>Estado</th>
                    </x-slot:head>
                    @forelse ($users as $u)
                        <tr wire:key="u{{ $u->id }}" @class(['opacity-60' => ! $u->active])>
                            <td>
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$u->name" size="sm" />
                                    <div class="min-w-0">
                                        <div class="font-medium text-slate-800">{{ $u->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $u->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-sm text-slate-600">{{ $u->roleLabel() }}</td>
                            <td class="text-sm text-slate-600">
                                {{ $u->last_login_at?->format('d/m/Y H:i') ?? 'Nunca' }}
                                @if ($u->last_login_ip)<div class="text-xs text-slate-400">{{ $u->last_login_ip }}</div>@endif
                            </td>
                            <td><x-ui.badge :color="$u->active ? 'success' : 'slate'" dot>{{ $u->active ? 'Activo' : 'Inactivo' }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="4" icon="users" title="Sin usuarios" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Datos de la empresa">
                <dl class="grid grid-cols-1 gap-4">
                    <x-ui.detail label="Razón social">{{ $tenant->legal_name }}</x-ui.detail>
                    <x-ui.detail label="RFC">{{ $tenant->rfc }}</x-ui.detail>
                    <x-ui.detail label="Correo">{{ $tenant->email }}</x-ui.detail>
                    <x-ui.detail label="Teléfono">{{ $tenant->phone }}</x-ui.detail>
                    <x-ui.detail label="Dirección">{{ $tenant->address }}</x-ui.detail>
                    <x-ui.detail label="Dominio / subdominio">{{ collect([$tenant->domain, $tenant->subdomain])->filter()->join(' · ') }}</x-ui.detail>
                    <x-ui.detail label="Moneda y zona horaria">{{ $tenant->currency }} · {{ $tenant->timezone }}</x-ui.detail>
                    <x-ui.detail label="Alta en la plataforma">{{ $tenant->created_at?->format('d/m/Y') }}</x-ui.detail>
                    <x-ui.detail label="Fin de prueba">{{ $tenant->trial_ends_at?->format('d/m/Y') }}</x-ui.detail>
                    <x-ui.detail label="Suscripción vigente hasta">
                        @if ($tenant->subscription_ends_at)
                            <span @class(['text-danger-600' => $tenant->subscription_ends_at->isPast()])>{{ $tenant->subscription_ends_at->format('d/m/Y') }}{{ $tenant->subscription_ends_at->isPast() ? ' (vencida)' : '' }}</span>
                        @endif
                    </x-ui.detail>
                </dl>
            </x-ui.card>

            <x-ui.card title="Clientes por estado">
                <div class="space-y-2">
                    @forelse ($clientsByStatus->sortDesc() as $status => $n)
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <x-ui.status catalog="client_status" :value="$status" />
                            <span class="font-semibold text-slate-800">{{ number_format($n) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">Sin clientes registrados.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card title="Equipos por estado">
                <div class="space-y-2">
                    @forelse ($devicesByStatus->sortDesc() as $status => $n)
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <x-ui.status catalog="device_status" :value="$status" />
                            <span class="font-semibold text-slate-800">{{ number_format($n) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-400">Sin equipos registrados.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card title="Actividad reciente">
                <ul class="space-y-3">
                    @forelse ($activity as $log)
                        <li class="text-sm">
                            <div class="text-slate-700"><span class="font-medium">{{ $log->user?->name ?? 'Sistema' }}</span> {{ $log->description }}</div>
                            <div class="text-xs text-slate-400" title="{{ $log->created_at?->format('d/m/Y H:i') }}">{{ $log->created_at?->diffForHumans() }}</div>
                        </li>
                    @empty
                        <li class="text-sm text-slate-400">Sin actividad registrada.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
