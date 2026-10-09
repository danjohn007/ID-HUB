<div>
    <x-ui.page-header :title="$client->full_name" :breadcrumbs="['Clientes' => route('clients.index'), '#'.$client->code => null]">
        @can('billing.manage')
            <x-ui.button icon="banknotes" variant="success" wire:click="$set('showPayment', true)">Registrar pago</x-ui.button>
        @endcan
        <x-ui.dropdown>
            <x-slot:trigger><x-ui.button variant="secondary" icon="ellipsis-horizontal">Acciones</x-ui.button></x-slot:trigger>
            @can('clients.manage')<x-ui.dropdown-item icon="pencil-square" :href="route('clients.edit', $client)">Editar datos</x-ui.dropdown-item>@endcan
            @can('billing.manage')
                <x-ui.dropdown-item icon="plus-circle" wire:click="$set('showCharge', true)">Agregar cargo</x-ui.dropdown-item>
                <x-ui.dropdown-item icon="link" wire:click="sendPaymentLink">Enviar enlace "Pagar ahora"</x-ui.dropdown-item>
            @endcan
            @can('tickets.manage')<x-ui.dropdown-item icon="ticket" wire:click="$set('showTicket', true)">Crear ticket</x-ui.dropdown-item>@endcan
            <x-ui.dropdown-item icon="chat-bubble-left-right" wire:click="$set('showMessage', true)">Enviar mensaje</x-ui.dropdown-item>
            <x-ui.dropdown-item icon="document-text" :href="route('documents.contract', $client)" target="_blank">Contrato (PDF)</x-ui.dropdown-item>
            <x-ui.dropdown-item icon="document-chart-bar" :href="route('documents.statement', $client)" target="_blank">Estado de cuenta (PDF)</x-ui.dropdown-item>
            @can('service.control')
                <div class="my-1 border-t border-slate-100"></div>
                @if ($client->service_status === 'suspendido')
                    <x-ui.dropdown-item icon="play" wire:click="reconnect" wire:confirm="¿Reactivar el servicio de este cliente?">Reactivar servicio</x-ui.dropdown-item>
                @elseif (in_array($client->service_status, ['instalacion_pendiente', 'prospecto', 'cancelado']))
                    <x-ui.dropdown-item icon="bolt" wire:click="activate" wire:confirm="¿Activar el servicio en MikroTik?">Activar servicio</x-ui.dropdown-item>
                @else
                    <x-ui.dropdown-item icon="pause" danger wire:click="$set('showSuspend', true)">Suspender servicio</x-ui.dropdown-item>
                @endif
                @if ($client->service_status !== 'cancelado')
                    <x-ui.dropdown-item icon="x-circle" danger wire:click="$set('showCancel', true)">Cancelar servicio</x-ui.dropdown-item>
                @endif
            @endcan
        </x-ui.dropdown>
    </x-ui.page-header>

    {{-- Encabezado de estado --}}
    <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="card col-span-2 flex items-center gap-4 p-4 lg:col-span-1">
            <span class="relative"><x-ui.avatar :name="$client->full_name" size="lg" />
                <span @class(['absolute right-0 bottom-0 h-3.5 w-3.5 rounded-full ring-2 ring-white', 'bg-success-500' => $client->online, 'bg-slate-300' => ! $client->online])></span></span>
            <div class="min-w-0">
                <div class="text-xs text-slate-500">Cliente #{{ $client->code }}</div>
                <x-ui.status catalog="client_status" :value="$client->service_status" class="mt-1" />
                <div class="mt-1 text-xs {{ $client->online ? 'text-success-600' : 'text-slate-500' }}">{{ $client->online ? 'En línea' : 'Desconectado' }}</div>
            </div>
        </div>
        <x-ui.stat variant="soft" label="Plan" :value="$client->plan?->name ?? '—'" :sub="$client->plan ? $client->plan->speedLabel().' · '.money($client->plan->price).'/'.mb_strtolower($client->plan->periodicityLabel()) : null" icon="rectangle-stack" color="brand" />
        <x-ui.stat variant="soft" label="Saldo pendiente" :value="money($client->balance)" :sub="$client->credit_balance > 0 ? 'Saldo a favor '.money($client->credit_balance) : null" icon="banknotes" :color="$client->balance > 0 ? 'danger' : 'success'" />
        <x-ui.stat variant="soft" label="Próximo pago" :value="$client->next_due_date?->translatedFormat('d M Y') ?? '—'" :sub="$client->next_due_date ? $client->next_due_date->diffForHumans() : null" icon="calendar-days" color="purple" />
        <x-ui.stat variant="soft" label="Riesgo de morosidad" :value="\App\Support\Catalog::label('risk_levels', $client->risk_level)" :sub="'Puntaje '.$client->risk_score.'/100'" icon="shield-exclamation" :color="\App\Support\Catalog::color('risk_levels', $client->risk_level)" />
    </div>

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="['resumen' => 'Resumen', 'cuenta' => 'Estado de cuenta', 'servicio' => 'Servicio y red', 'tickets' => 'Tickets y visitas', 'documentos' => 'Documentos y evidencias', 'mensajes' => 'Mensajes', 'bitacora' => 'Bitácora']" />

    {{-- ============================== RESUMEN ============================== --}}
    @if ($tab === 'resumen')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Información del cliente">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-3">
                        <x-ui.detail label="Teléfono">{{ $client->phone }}</x-ui.detail>
                        <x-ui.detail label="WhatsApp">
                            @if ($client->whatsapp)<a class="link" target="_blank" href="https://wa.me/{{ preg_replace('/\D/', '', strlen(preg_replace('/\D/', '', $client->whatsapp)) === 10 ? '52'.$client->whatsapp : $client->whatsapp) }}">{{ $client->whatsapp }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="Correo">{{ $client->email }}</x-ui.detail>
                        <x-ui.detail label="Dirección" class="md:col-span-2">{{ $client->address }} {{ $client->colonia ? '· '.$client->colonia : '' }}</x-ui.detail>
                        <x-ui.detail label="Referencias">{{ $client->references }}</x-ui.detail>
                        <x-ui.detail label="Zona / Comunidad">{{ $client->zone?->name }} {{ $client->community ? '· '.$client->community : '' }}</x-ui.detail>
                        <x-ui.detail label="CURP / RFC">{{ trim($client->curp.' '.$client->rfc) }}</x-ui.detail>
                        <x-ui.detail label="Fecha de instalación">{{ $client->installation_date?->translatedFormat('d M Y') }}</x-ui.detail>
                        <x-ui.detail label="Técnico responsable">{{ $client->technician?->name }}</x-ui.detail>
                        <x-ui.detail label="Cobrador">{{ $client->collector?->name }}</x-ui.detail>
                        <x-ui.detail label="Cliente desde">{{ $client->created_at->translatedFormat('M Y') }}</x-ui.detail>
                    </dl>
                    @if ($client->notes)
                        <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ $client->notes }}</div>
                    @endif
                </x-ui.card>

                <x-ui.card title="Red">
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-4">
                        <x-ui.detail label="Repetidor">
                            @if ($client->repeater)<a class="link" href="{{ Route::has('network.repeaters.show') ? route('network.repeaters.show', $client->repeater) : '#' }}" wire:navigate>{{ $client->repeater->code }} · {{ $client->repeater->name }}</a>@endif
                        </x-ui.detail>
                        <x-ui.detail label="MikroTik">{{ ($client->router ?? $client->repeater?->router)?->name }}</x-ui.detail>
                        <x-ui.detail label="Antena / CPE">{{ $client->cpe?->name }} {{ $client->cpe?->model ? '· '.$client->cpe->model : '' }}</x-ui.detail>
                        <x-ui.detail label="Señal">
                            @if ($client->signal_dbm)<span class="{{ $client->signal_dbm < -78 ? 'text-danger-600' : ($client->signal_dbm < -70 ? 'text-warning-600' : 'text-success-600') }}">{{ $client->signal_dbm }} dBm</span>@endif
                        </x-ui.detail>
                        <x-ui.detail label="IP">{{ $client->ip_address }}</x-ui.detail>
                        <x-ui.detail label="MAC">{{ $client->mac_address }}</x-ui.detail>
                        <x-ui.detail label="Usuario PPPoE">{{ $client->pppoe_user }}</x-ui.detail>
                        <x-ui.detail label="Consumo del mes">{{ number_format($client->month_usage_gb, 1) }} GB</x-ui.detail>
                    </dl>
                </x-ui.card>

                @if ($client->lat)
                    <x-ui.card title="Ubicación" :padding="false">
                        <x-ui.map id="client-loc" height="260px" :center="[(float) $client->lat, (float) $client->lng]" :zoom="16" class="rounded-t-none border-0"
                                  :markers="array_values(array_filter([
                                      ['lat' => (float) $client->lat, 'lng' => (float) $client->lng, 'type' => 'client', 'status' => $client->mapStatus(), 'size' => 30, 'popup' => e($client->full_name)],
                                      $client->repeater?->lat ? ['lat' => (float) $client->repeater->lat, 'lng' => (float) $client->repeater->lng, 'type' => 'repeater', 'status' => $client->repeater->status, 'popup' => $client->repeater->code] : null,
                                  ]))"
                                  :lines="$client->repeater?->lat ? [['points' => [[(float) $client->lat, (float) $client->lng], [(float) $client->repeater->lat, (float) $client->repeater->lng]], 'dash' => true, 'weight' => 2]] : []" />
                    </x-ui.card>
                @endif
            </div>

            <div class="space-y-5">
                <x-ui.card title="Cargos pendientes">
                    @forelse ($openCharges as $ch)
                        <div class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                            <div><div class="font-medium text-slate-700">{{ $ch->description }}</div><div class="text-xs {{ $ch->isOverdue() ? 'text-danger-600' : 'text-slate-500' }}">Vence {{ $ch->due_date->translatedFormat('d M Y') }}</div></div>
                            <div class="font-semibold text-slate-800">{{ money($ch->balance()) }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-success-600">Sin adeudos. ¡Al corriente!</p>
                    @endforelse
                </x-ui.card>

                <x-ui.card title="Riesgo de morosidad">
                    <x-slot:actions><x-ui.button size="sm" variant="ghost" icon="arrow-path" wire:click="recalculateRisk" /></x-slot:actions>
                    <div class="flex items-center gap-3">
                        <div class="text-3xl font-bold text-navy-900">{{ $risk['score'] }}</div>
                        <div class="flex-1"><x-ui.progress :value="$risk['score']" :color="\App\Support\Catalog::color('risk_levels', $risk['level'])" /></div>
                        <x-ui.status catalog="risk_levels" :value="$risk['level']" />
                    </div>
                    <dl class="mt-3 space-y-1.5 text-xs">
                        @foreach ($risk['factors'] as $label => $value)
                            <div class="flex justify-between"><dt class="text-slate-500">{{ $label }}</dt><dd class="font-semibold text-slate-700">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-[11px] text-slate-400">Indicador basado en comportamiento histórico; no es garantía de incumplimiento.</p>
                </x-ui.card>

                <x-ui.card title="Programa de referidos">
                    <div class="rounded-lg bg-brand-50 p-3 text-center">
                        <div class="text-xs text-brand-700">Código del cliente</div>
                        <div class="text-xl font-extrabold tracking-wider text-brand-700">{{ $client->referral_code }}</div>
                        <div class="mt-1 truncate text-[11px] text-slate-500">{{ $referralUrl }}</div>
                    </div>
                    @if ($client->referrer)<p class="mt-2 text-xs text-slate-500">Referido por <a class="link" href="{{ route('clients.show', $client->referrer) }}" wire:navigate>{{ $client->referrer->full_name }}</a></p>@endif
                    @foreach ($referrals as $r)
                        <div class="mt-2 flex items-center justify-between text-xs"><span>{{ $r->referred?->full_name ?? 'Prospecto' }}</span><x-ui.status catalog="referral_status" :value="$r->status" size="xs" /></div>
                    @endforeach
                </x-ui.card>
            </div>
        </div>
    @endif

    {{-- ============================== ESTADO DE CUENTA ============================== --}}
    @if ($tab === 'cuenta')
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <x-ui.stat variant="soft" label="Saldo actual" :value="money($client->balance)" icon="scale" :color="$client->balance > 0 ? 'danger' : 'success'" />
            <x-ui.stat variant="soft" label="Saldo vencido" :value="money($overdue)" icon="exclamation-circle" :color="$overdue > 0 ? 'danger' : 'slate'" />
            <x-ui.stat variant="soft" label="Saldo a favor" :value="money($client->credit_balance)" icon="gift" color="teal" />
            <x-ui.stat variant="soft" label="Total pagado (histórico)" :value="money($paidTotal)" icon="check-badge" color="success" />
        </div>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <x-ui.card title="Cargos" :padding="false">
                <x-slot:actions>@can('billing.manage')<x-ui.button size="sm" variant="secondary" icon="plus" wire:click="$set('showCharge', true)">Cargo</x-ui.button>@endcan</x-slot:actions>
                <x-ui.table>
                    <x-slot:head><th>Concepto</th><th>Vence</th><th class="text-right">Total</th><th>Estado</th><th></th></x-slot:head>
                    @forelse ($charges as $ch)
                        <tr wire:key="ch{{ $ch->id }}">
                            <td><div class="font-medium text-slate-700">{{ $ch->description }}</div><div class="text-xs text-slate-400">{{ $ch->folio }} · {{ config('wisp.charge_types.'.$ch->type) }}</div></td>
                            <td class="text-xs {{ $ch->isOverdue() ? 'font-semibold text-danger-600' : 'text-slate-600' }}">{{ $ch->due_date->format('d/m/Y') }}</td>
                            <td class="text-right font-semibold">{{ money($ch->total) }}@if ($ch->status === 'partial')<div class="text-[11px] font-normal text-slate-500">Resta {{ money($ch->balance()) }}</div>@endif</td>
                            <td><x-ui.status catalog="charge_status" :value="$ch->status" size="xs" /></td>
                            <td class="text-right">
                                @can('billing.manage')
                                    @if (in_array($ch->status, ['pending', 'partial']))
                                        <button wire:click="cancelCharge({{ $ch->id }})" wire:confirm="¿Cancelar este cargo?" class="text-xs text-slate-400 hover:text-danger-600" title="Cancelar"><x-heroicon-o-x-circle class="h-4 w-4" /></button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="5" icon="document" title="Sin cargos" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Historial de pagos" :padding="false">
                <x-slot:actions>@can('billing.manage')<x-ui.button size="sm" variant="success" icon="plus" wire:click="$set('showPayment', true)">Pago</x-ui.button>@endcan</x-slot:actions>
                <x-ui.table>
                    <x-slot:head><th>Folio</th><th>Fecha</th><th>Método</th><th class="text-right">Importe</th><th></th></x-slot:head>
                    @forelse ($payments as $p)
                        <tr wire:key="p{{ $p->id }}" @class(['opacity-50' => $p->status === 'cancelled'])>
                            <td><div class="font-medium text-slate-700">{{ $p->folio }}</div>@if ($p->status !== 'completed')<x-ui.status catalog="payment_status" :value="$p->status" size="xs" />@endif</td>
                            <td class="text-xs text-slate-600">{{ $p->paid_at->format('d/m/Y H:i') }}<div class="text-slate-400">{{ $p->collector?->name ?? $p->receivedBy?->name }}</div></td>
                            <td class="text-xs">{{ $p->methodLabel() }}@if ($p->reference)<div class="text-slate-400">Ref. {{ $p->reference }}</div>@endif</td>
                            <td class="text-right font-semibold {{ $p->kind === 'credit' ? 'text-teal-700' : 'text-success-700' }}">{{ money($p->amount) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('documents.receipt', $p) }}" target="_blank" class="inline-block text-slate-400 hover:text-brand-600" title="Comprobante"><x-heroicon-o-printer class="h-4 w-4" /></a>
                                @can('billing.manage')
                                    @if ($p->status === 'completed')
                                        <button wire:click="cancelPayment({{ $p->id }})" wire:confirm="¿Cancelar este pago? Se revertirá su aplicación a los cargos." class="ml-1 text-slate-400 hover:text-danger-600" title="Cancelar"><x-heroicon-o-x-circle class="h-4 w-4" /></button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="5" icon="banknotes" title="Sin pagos registrados" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
        @if ($links->isNotEmpty())
            <x-ui.card title="Enlaces de pago" class="mt-5" :padding="false">
                <x-ui.table>
                    <x-slot:head><th>Creado</th><th>Importe</th><th>Estado</th><th>Vence</th><th>Enlace</th></x-slot:head>
                    @foreach ($links as $l)
                        <tr><td class="text-xs">{{ $l->created_at->format('d/m/Y H:i') }}</td><td class="font-semibold">{{ money($l->amount) }}</td>
                            <td><x-ui.badge :color="['pending' => 'warning', 'paid' => 'success'][$l->status] ?? 'slate'">{{ ['pending' => 'Pendiente', 'paid' => 'Pagado', 'expired' => 'Expirado', 'cancelled' => 'Cancelado'][$l->status] ?? $l->status }}</x-ui.badge></td>
                            <td class="text-xs">{{ $l->expires_at?->format('d/m/Y') }}</td>
                            <td class="text-xs"><a href="{{ $l->url() }}" target="_blank" class="link">Abrir</a></td></tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>
        @endif
    @endif

    {{-- ============================== SERVICIO ============================== --}}
    @if ($tab === 'servicio')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5">
                <x-ui.card title="Control del servicio">
                    <div class="space-y-2">
                        @can('service.control')
                            @if ($client->service_status === 'suspendido')
                                <x-ui.button variant="success" icon="play" class="w-full" wire:click="reconnect" wire:confirm="¿Reactivar el servicio?">Reactivar servicio</x-ui.button>
                            @elseif (in_array($client->service_status, ['instalacion_pendiente', 'prospecto', 'cancelado']))
                                <x-ui.button icon="bolt" class="w-full" wire:click="activate">Activar en MikroTik</x-ui.button>
                            @else
                                <x-ui.button variant="danger" icon="pause" class="w-full" wire:click="$set('showSuspend', true)">Suspender servicio</x-ui.button>
                            @endif
                        @endcan
                        @can('clients.manage')<x-ui.button variant="secondary" icon="arrows-up-down" class="w-full" wire:click="$set('showPlan', true)">Cambiar plan / velocidad</x-ui.button>@endcan
                        <x-ui.button variant="secondary" icon="signal" class="w-full" wire:click="testConnection">Consultar conexión en MikroTik</x-ui.button>
                    </div>
                    @if ($connection)
                        <div class="mt-4 rounded-lg border border-slate-200 p-3 text-sm">
                            @if (isset($connection['error']))
                                <p class="text-danger-600">{{ $connection['error'] }}</p>
                            @else
                                <div class="mb-2 flex items-center justify-between"><span class="font-semibold">{{ $connection['router'] }}</span>
                                    <x-ui.badge :color="$connection['online'] ? 'success' : 'slate'" dot>{{ $connection['online'] ? 'Conectado' : 'Sin sesión activa' }}</x-ui.badge></div>
                                <dl class="grid grid-cols-2 gap-2 text-xs">
                                    <x-ui.detail label="Tipo">{{ $connection['type'] }}</x-ui.detail>
                                    <x-ui.detail label="IP">{{ $connection['address'] }}</x-ui.detail>
                                    <x-ui.detail label="MAC">{{ $connection['mac'] }}</x-ui.detail>
                                    <x-ui.detail label="Uptime">{{ $connection['uptime'] }}</x-ui.detail>
                                    <x-ui.detail label="Señal">{{ $connection['signal'] ? $connection['signal'].' dBm' : '' }}</x-ui.detail>
                                </dl>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
                <x-ui.card title="Equipos instalados">
                    @forelse ($devices as $d)
                        <div class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                            <div><div class="font-medium">{{ $d->name }}</div><div class="text-xs text-slate-500">{{ $d->model }} · {{ $d->mac }}</div></div>
                            <x-ui.status catalog="device_status" :value="$d->status" size="xs" />
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Sin equipos asociados.</p>
                    @endforelse
                    <p class="mt-3 text-xs text-slate-500">Cadena: Cliente → CPE → {{ $client->repeater?->code ?? 'Repetidor' }} → {{ $client->repeater?->tower?->name ?? 'Torre' }}</p>
                </x-ui.card>
            </div>
            <x-ui.card title="Historial de servicio (cortes, reconexiones, cambios)" :padding="false" class="xl:col-span-2">
                <x-ui.table>
                    <x-slot:head><th>Fecha</th><th>Evento</th><th>Motivo</th><th>Origen</th><th>Resultado en red</th></x-slot:head>
                    @forelse ($events as $e)
                        @php [$label, $color] = \App\Models\ServiceEvent::TYPES[$e->type] ?? [$e->type, 'slate']; @endphp
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ $e->created_at->format('d/m/Y H:i') }}</td>
                            <td><x-ui.badge :color="$color">{{ $label }}</x-ui.badge></td>
                            <td class="text-xs">{{ $e->reason }}</td>
                            <td class="text-xs">{{ ['manual' => 'Manual', 'manual_nofee' => 'Manual', 'automation' => 'Automatización', 'payment' => 'Pago', 'system' => 'Sistema'][$e->source] ?? $e->source }}{{ $e->user ? ' · '.$e->user->name : '' }}</td>
                            <td class="text-xs"><span class="{{ $e->network_applied ? 'text-success-700' : 'text-warning-700' }}">{{ $e->network_result }}</span></td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="5" title="Sin eventos" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    @endif

    {{-- ============================== TICKETS ============================== --}}
    @if ($tab === 'tickets')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <x-ui.card title="Tickets" :padding="false" class="xl:col-span-2">
                <x-slot:actions>@can('tickets.manage')<x-ui.button size="sm" variant="secondary" icon="plus" wire:click="$set('showTicket', true)">Nuevo ticket</x-ui.button>@endcan</x-slot:actions>
                <x-ui.table>
                    <x-slot:head><th>Folio</th><th>Asunto</th><th>Prioridad</th><th>Estado</th><th>Técnico</th><th>Fecha</th></x-slot:head>
                    @forelse ($tickets as $t)
                        <tr>
                            <td><a class="link" href="{{ Route::has('tickets.show') ? route('tickets.show', $t) : '#' }}" wire:navigate>{{ $t->folio }}</a></td>
                            <td class="text-sm">{{ $t->subject }}<div class="text-xs text-slate-400">{{ config('wisp.ticket_categories.'.$t->category) }}</div></td>
                            <td><x-ui.status catalog="ticket_priorities" :value="$t->priority" size="xs" /></td>
                            <td><x-ui.status catalog="ticket_status" :value="$t->status" size="xs" /></td>
                            <td class="text-xs">{{ $t->technician?->name }}</td>
                            <td class="text-xs">{{ $t->created_at->format('d/m/Y') }}</td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="ticket" title="Sin tickets" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="Visitas de cobranza">
                @forelse ($visits as $v)
                    <div class="border-b border-slate-100 py-2 text-sm last:border-0">
                        <div class="flex items-center justify-between"><x-ui.status catalog="visit_results" :value="$v->result" size="xs" /><span class="text-xs text-slate-400">{{ $v->visited_at->format('d/m/Y H:i') }}</span></div>
                        <div class="mt-1 text-xs text-slate-600">{{ $v->collector?->name }} @if ($v->promise_date)· Promesa {{ $v->promise_date->format('d/m') }} por {{ money($v->promise_amount) }}@endif</div>
                        @if ($v->notes)<div class="text-xs text-slate-500">{{ $v->notes }}</div>@endif
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Sin visitas registradas.</p>
                @endforelse
            </x-ui.card>
        </div>
    @endif

    {{-- ============================== DOCUMENTOS ============================== --}}
    @if ($tab === 'documentos')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <x-ui.card title="Documentos generados">
                <div class="space-y-2">
                    <a href="{{ route('documents.contract', $client) }}" target="_blank" class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 hover:border-brand-300 hover:bg-brand-50/40">
                        <x-heroicon-o-document-text class="h-6 w-6 text-brand-600" /><div><div class="text-sm font-semibold">Contrato de servicio</div><div class="text-xs text-slate-500">PDF con firma</div></div></a>
                    <a href="{{ route('documents.statement', $client) }}" target="_blank" class="flex items-center gap-3 rounded-lg border border-slate-200 p-3 hover:border-brand-300 hover:bg-brand-50/40">
                        <x-heroicon-o-document-chart-bar class="h-6 w-6 text-brand-600" /><div><div class="text-sm font-semibold">Estado de cuenta</div><div class="text-xs text-slate-500">Cargos y pagos</div></div></a>
                </div>
                <h4 class="mt-5 mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Comprobantes de pago</h4>
                @foreach ($receipts as $p)
                    <a href="{{ route('documents.receipt', $p) }}" target="_blank" class="flex items-center justify-between py-1.5 text-sm hover:text-brand-600">
                        <span>{{ $p->folio }} · {{ $p->paid_at->format('d/m/Y') }}</span><span class="font-semibold">{{ money($p->amount) }}</span></a>
                @endforeach
            </x-ui.card>
            <x-ui.card title="Evidencias y archivos" class="xl:col-span-2">
                <form wire:submit="uploadEvidence" class="mb-4 flex flex-wrap items-end gap-3">
                    <x-ui.select label="Tipo" wire:model="evidenceKind" :options="config('wisp.evidence_kinds')" />
                    <div><label class="form-label">Archivo (imagen o PDF)</label><input type="file" wire:model="evidence" class="text-sm"></div>
                    <x-ui.button type="submit" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="evidence,uploadEvidence">Subir</x-ui.button>
                    @error('evidence')<p class="w-full text-xs text-danger-600">{{ $message }}</p>@enderror
                </form>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @forelse ($attachments as $a)
                        <a href="{{ $a->url() }}" target="_blank" class="group overflow-hidden rounded-lg border border-slate-200">
                            @if ($a->isImage())<img src="{{ $a->url() }}" class="h-28 w-full object-cover group-hover:opacity-90">@else<div class="flex h-28 items-center justify-center bg-slate-50"><x-heroicon-o-document class="h-10 w-10 text-slate-400" /></div>@endif
                            <div class="p-2 text-xs"><div class="font-semibold">{{ config('wisp.evidence_kinds.'.$a->kind) }}</div><div class="text-slate-400">{{ $a->created_at->format('d/m/Y H:i') }}</div></div>
                        </a>
                    @empty
                        <p class="col-span-full text-sm text-slate-500">Sin evidencias.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- ============================== MENSAJES ============================== --}}
    @if ($tab === 'mensajes')
        <x-ui.card title="Mensajes enviados" :padding="false">
            <x-slot:actions><x-ui.button size="sm" variant="secondary" icon="paper-airplane" wire:click="$set('showMessage', true)">Enviar mensaje</x-ui.button></x-slot:actions>
            <x-ui.table>
                <x-slot:head><th>Fecha</th><th>Canal</th><th>Evento</th><th>Mensaje</th><th>Estado</th></x-slot:head>
                @forelse ($messages as $m)
                    <tr>
                        <td class="text-xs whitespace-nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                        <td class="text-xs">{{ config('wisp.channels.'.$m->channel) }}</td>
                        <td class="text-xs">{{ config('wisp.message_events.'.$m->event, $m->event) }}</td>
                        <td class="max-w-md text-xs text-slate-600">{{ $m->body }}</td>
                        <td>@php [$l, $col] = \App\Models\Message::STATUS[$m->status] ?? [$m->status, 'slate']; @endphp<x-ui.badge :color="$col" size="xs">{{ $l }}</x-ui.badge></td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" icon="chat-bubble-left" title="Sin mensajes" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ============================== BITÁCORA ============================== --}}
    @if ($tab === 'bitacora')
        <x-ui.card title="Bitácora de auditoría" :padding="false">
            <x-ui.table>
                <x-slot:head><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Cambios</th><th>IP</th></x-slot:head>
                @forelse ($logs as $log)
                    <tr>
                        <td class="text-xs whitespace-nowrap">{{ $log->created_at->format('d/m/Y H:i') }}</td>
                        <td class="text-xs">{{ $log->user_name }}</td>
                        <td class="text-sm">{{ $log->description }}</td>
                        <td class="text-xs text-slate-600">
                            @foreach ($log->new_values ?? [] as $k => $v)
                                <div><span class="font-medium">{{ $k }}</span>: <span class="text-slate-400 line-through">{{ is_scalar($log->old_values[$k] ?? null) ? $log->old_values[$k] : '' }}</span> → {{ is_scalar($v) ? $v : json_encode($v) }}</div>
                            @endforeach
                        </td>
                        <td class="text-xs">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="5" title="Sin registros" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- ============================== MODALES ============================== --}}
    <x-ui.modal wire:model="showPayment" title="Registrar pago" size="md">
        <div class="space-y-4">
            <div class="rounded-lg bg-slate-50 p-3 text-sm">Saldo pendiente: <b>{{ money($client->balance) }}</b></div>
            <x-ui.input label="Importe" type="number" step="0.01" wire:model="payment.amount" prefix="$" required />
            <x-ui.select label="Método de pago" wire:model.live="payment.method" :options="config('wisp.payment_methods')" />
            <x-ui.input label="Referencia" wire:model="payment.reference" placeholder="Folio de transferencia, autorización..." />
            <x-ui.input label="Notas" wire:model="payment.notes" />
            <x-ui.toggle wire:model="payment.notify" label="Enviar confirmación por WhatsApp" />
            @if ($client->service_status === 'suspendido')
                <div class="rounded-lg bg-success-50 p-3 text-xs text-success-700">Si el pago liquida el saldo, el servicio se reactivará automáticamente en MikroTik.</div>
            @endif
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="success" icon="check" wire:click="registerPayment">Aplicar pago</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showCharge" title="Agregar cargo" size="md">
        <div class="space-y-4">
            <x-ui.select label="Tipo" wire:model="charge.type" :options="config('wisp.charge_types')" />
            <x-ui.input label="Concepto" wire:model="charge.description" required />
            <x-ui.input label="Importe (IVA incluido)" type="number" step="0.01" wire:model="charge.amount" prefix="$" required />
            <x-ui.input label="Fecha límite" type="date" wire:model="charge.due_date" required />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="addCharge">Agregar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showSuspend" title="Suspender servicio" size="md">
        <p class="mb-3 text-sm text-slate-600">Se bloqueará el servicio en MikroTik ({{ ($client->plan?->block_type ?: tenant()->setting('billing.block_type')) === 'partial' ? 'bloqueo parcial: se redirige al portal de pago' : 'bloqueo total' }}) y se notificará al cliente.</p>
        <x-ui.input label="Motivo" wire:model="reason" placeholder="Falta de pago" />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button variant="danger" icon="pause" wire:click="suspend">Suspender</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showCancel" title="Cancelar servicio" size="md">
        <p class="mb-3 text-sm text-slate-600">El cliente pasará a estado <b>Cancelado</b> y se eliminará su usuario del MikroTik.</p>
        <x-ui.select label="Motivo" wire:model="reason" :options="array_combine($r = ['Cambio de domicilio', 'Contrató otro proveedor', 'Problemas económicos', 'Mala señal', 'Ya no lo necesita', 'Otro'], $r)" placeholder="Selecciona..." />
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cerrar</x-ui.button>
            <x-ui.button variant="danger" icon="x-circle" wire:click="cancelService">Cancelar servicio</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showPlan" title="Cambiar plan" size="lg">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach (\App\Models\Plan::where('active', true)->orderBy('price')->get() as $p)
                <label @class(['cursor-pointer rounded-xl border-2 p-4 transition', 'border-brand-600 bg-brand-50' => (int) $newPlanId === $p->id, 'border-slate-200 hover:border-slate-300' => (int) $newPlanId !== $p->id])>
                    <input type="radio" wire:model.live="newPlanId" value="{{ $p->id }}" class="sr-only">
                    <div class="flex items-center justify-between"><span class="font-bold text-navy-900">{{ $p->name }}</span>@if ($client->plan_id === $p->id)<x-ui.badge color="brand" size="xs">Actual</x-ui.badge>@endif</div>
                    <div class="mt-1 text-2xl font-bold" style="color: {{ $p->color }}">{{ $p->speedLabel() }}</div>
                    <div class="text-sm text-slate-500">{{ money($p->price) }} / {{ mb_strtolower($p->periodicityLabel()) }}</div>
                </label>
            @endforeach
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="changePlan">Aplicar cambio</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showTicket" title="Nuevo ticket" size="lg">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.select label="Categoría" wire:model="ticket.category" :options="config('wisp.ticket_categories')" />
            <x-ui.select label="Prioridad" wire:model="ticket.priority" :options="config('wisp.ticket_priorities')" />
            <x-ui.input label="Asunto" wire:model="ticket.subject" class="sm:col-span-2" required />
            <x-ui.textarea label="Descripción" wire:model="ticket.description" class="sm:col-span-2" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="createTicket">Crear ticket</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal wire:model="showMessage" title="Enviar mensaje" size="md">
        <div class="space-y-4">
            <x-ui.select label="Canal" wire:model="message.channel" :options="['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Correo']" />
            <x-ui.textarea label="Mensaje" wire:model="message.body" rows="4" hint="Variables: {nombre}, {saldo}, {plan}, {fecha_limite}, {empresa}" />
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="paper-airplane" wire:click="sendMessage">Enviar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
