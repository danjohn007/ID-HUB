<div>
    <x-ui.page-header title="Programa de referidos" subtitle="Cada cliente tiene un código y enlace para invitar; se valida con el primer pago y se recompensa">
        @if (! $config['enabled'])<x-ui.badge color="warning" dot>Programa desactivado</x-ui.badge>@endif
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-ui.stat variant="soft" label="Referidos" :value="$kpi['total']" icon="user-group" color="brand" />
        <x-ui.stat variant="soft" label="Pendientes" :value="$kpi['pending']" icon="clock" color="warning" />
        <x-ui.stat variant="soft" label="Validados" :value="$kpi['validated']" icon="shield-check" color="purple" />
        <x-ui.stat variant="soft" label="Recompensados" :value="$kpi['rewarded']" icon="gift" color="success" />
        <x-ui.stat variant="soft" label="Recompensas otorgadas" :value="money($kpi['rewardedAmount'], false)" icon="banknotes" color="teal" />
        <x-ui.stat variant="soft" label="Prospectos por referido" :value="$kpi['leads']" :sub="$kpi['leadsOpen'].' en seguimiento'" icon="user-plus" color="navy" />
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-ui.card :padding="false">
                <div class="flex flex-col gap-3 border-b border-slate-100 p-4 md:flex-row md:items-center">
                    <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Código, cliente que refiere o referido..." class="flex-1" />
                    <div class="flex gap-1.5 overflow-x-auto">
                        <button wire:click="$set('filterStatus', '')" @class(['whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === '', 'bg-white text-slate-600 ring-slate-200' => $filterStatus !== ''])>Todos</button>
                        @foreach (config('wisp.referral_status') as $key => [$label])
                            <button wire:click="$set('filterStatus', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold ring-1', 'bg-navy-900 text-white ring-navy-900' => $filterStatus === $key, 'bg-white text-slate-600 ring-slate-200' => $filterStatus !== $key])>{{ $label }} <span class="opacity-70">{{ $counts[$key] ?? 0 }}</span></button>
                        @endforeach
                    </div>
                </div>
                <x-ui.table>
                    <x-slot:head>
                        <th>Refiere</th><th>Referido</th><th>Código</th><th>Estado</th><th>Recompensa</th><x-ui.th sort="created_at" :sort-by="$sortBy" :sort-dir="$sortDir">Fecha</x-ui.th><th></th>
                    </x-slot:head>
                    @forelse ($referrals as $r)
                        <tr wire:key="ref{{ $r->id }}">
                            <td>
                                @if ($r->referrer)
                                    <a href="{{ route('clients.show', $r->referrer) }}" wire:navigate class="font-semibold text-slate-800 hover:text-brand-600">{{ $r->referrer->full_name }}</a>
                                    <div class="text-xs text-slate-500">#{{ $r->referrer->code }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($r->referred)
                                    <a href="{{ route('clients.show', $r->referred) }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-600">{{ $r->referred->full_name }}</a>
                                    <div class="text-xs"><x-ui.status catalog="client_status" :value="$r->referred->service_status" size="xs" :dot="false" /></div>
                                @elseif ($r->prospect)
                                    <span class="text-slate-700">{{ $r->prospect->name }}</span><div class="text-xs text-slate-500">Prospecto</div>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="font-mono text-xs font-semibold">{{ $r->code }}</td>
                            <td><x-ui.status catalog="referral_status" :value="$r->status" /></td>
                            <td class="text-xs">
                                <div class="text-slate-700">{{ config('wisp.reward_types.'.$r->reward_type, '—') }}</div>
                                <div class="text-slate-500">{{ $r->status === 'recompensado' ? money($r->reward_value).' · '.$r->rewarded_at?->format('d/m/Y') : ($r->reward_type === 'mes_gratis' ? money($r->referrer?->plan?->price ?? 0).' (1 mes)' : money($r->reward_value)) }}</div>
                            </td>
                            <td class="text-xs whitespace-nowrap text-slate-600">{{ $r->created_at?->format('d/m/Y') }}</td>
                            <td class="text-right whitespace-nowrap">
                                @if (in_array($r->status, ['pendiente', 'validado']))
                                    <x-ui.button size="sm" variant="success" icon="gift" wire:click="validateAndReward({{ $r->id }})" wire:confirm="¿Validar y aplicar la recompensa al cliente que refirió?">Validar y recompensar</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" icon="x-mark" wire:click="cancel({{ $r->id }})" wire:confirm="¿Cancelar este referido?" title="Cancelar" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="7" icon="user-group" title="Sin referidos" description="Los referidos aparecen cuando un cliente nuevo se registra con un código." />
                    @endforelse
                </x-ui.table>
                <div class="border-t border-slate-100 px-4 py-3">{{ $referrals->links() }}</div>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            <x-ui.card title="Configuración del programa">
                <form wire:submit="saveConfig" class="space-y-3">
                    <x-ui.toggle wire:model="config.enabled" label="Programa activo" description="Muestra el enlace de referido y registra recompensas" />
                    <x-ui.select label="Tipo de recompensa" wire:model.live="config.reward_type" :options="config('wisp.reward_types')" required />
                    @if ($config['reward_type'] !== 'mes_gratis')
                        <x-ui.input label="Valor de la recompensa" type="number" step="0.01" min="0" max="100000" wire:model="config.reward_value" prefix="$" hint="Se aplica como saldo a favor / bonificación al cliente que refiere" />
                    @else
                        <p class="rounded-lg bg-slate-50 p-2.5 text-xs text-slate-500">Se bonifica el importe de una mensualidad del plan del cliente que refiere.</p>
                    @endif
                    <x-ui.input label="Prefijo del código" wire:model.live.debounce.400ms="config.code_prefix" filter="upper" maxlength="12" :hint="'Ejemplo: '.$example" required />
                    <x-ui.button type="submit" icon="check" class="w-full">Guardar configuración</x-ui.button>
                </form>
                <div class="mt-4 rounded-lg bg-brand-50 p-3 text-xs text-brand-800">
                    <b>Flujo:</b> el cliente comparte su enlace <span class="font-mono">{{ url('/r/'.strtolower($example)) }}</span> → el interesado se registra como prospecto → al contratar se registra el referido → con el primer pago se valida y se genera la recompensa.
                </div>
                <div class="mt-2 text-xs text-slate-500">{{ number_format($kpi['withCode']) }} clientes con código asignado.</div>
            </x-ui.card>

            <x-ui.card title="Top referidores">
                @forelse ($top as $i => $t)
                    <div class="flex items-center gap-3 border-b border-slate-100 py-2 last:border-0">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $loop->index < 3 ? 'bg-warning-100 text-warning-700' : 'bg-slate-100 text-slate-500' }} text-xs font-bold">{{ $loop->iteration }}</span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('clients.show', $t['client']) }}" wire:navigate class="block truncate text-sm font-semibold text-slate-800 hover:text-brand-600">{{ $t['client']->full_name }}</a>
                            <div class="text-xs text-slate-500">{{ $t['client']->referral_code }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-bold text-navy-900">{{ $t['total'] }}</div>
                            <div class="text-[11px] text-success-600">{{ money($t['earned'], false) }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Sin referidores aún.</p>
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
