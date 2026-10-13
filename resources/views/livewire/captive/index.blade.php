<div>
    <x-ui.page-header title="Portal cautivo" subtitle="Acceso al WiFi del hotspot, publicidad local, fichas y sesiones">
        <x-ui.button variant="secondary" icon="arrow-top-right-on-square" :href="route('captive.portal', $tenantSlug)" :navigate="false" target="_blank">Ver portal</x-ui.button>
    </x-ui.page-header>

    @unless (tenant()->hasFeature('portal_cautivo'))
        <div class="mb-5 flex gap-2 rounded-xl bg-warning-50 px-4 py-3 text-sm text-warning-800">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
            <span>El plan SaaS de tu empresa no incluye el portal cautivo: puedes configurarlo, pero la página pública no estará disponible hasta cambiar de plan.</span>
        </div>
    @endunless

    <x-ui.tabs wire:model.live="tab" class="mb-5" :tabs="['configuracion' => 'Configuración', 'anuncios' => 'Publicidad', 'vouchers' => 'Fichas', 'sesiones' => 'Sesiones']" />

    {{-- ============================================================ Configuración --}}
    @if ($tab === 'configuracion')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
            <x-ui.card title="Personalización" subtitle="Lo que ven las personas al conectarse al WiFi">
                <form wire:submit="saveSettings" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.input label="Título" wire:model="settings.title" filter="text" maxlength="80" required />
                    <div>
                        <label class="form-label">Color de fondo</label>
                        <div class="flex gap-2">
                            <input type="color" wire:model.live="settings.background" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-300 bg-white p-1">
                            <input wire:model.live="settings.background" data-filter="color" maxlength="7" class="form-input font-mono">
                        </div>
                        @error('settings.background')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.input label="Mensaje de bienvenida" wire:model="settings.welcome" maxlength="300" class="sm:col-span-2" />

                    <div class="sm:col-span-2">
                        <label class="form-label">Formas de acceso <span class="text-danger-600">*</span></label>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            @foreach (config('wisp.growth.captive_login_methods') as $key => $label)
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                                    <input type="checkbox" value="{{ $key }}" wire:model="settings.methods" class="rounded border-slate-300"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('settings.methods')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                    </div>

                    <x-ui.textarea label="Aviso destacado" wire:model="settings.notice" rows="2" maxlength="500" hint="Ej. mantenimiento programado. Vacío = sin aviso" class="sm:col-span-2" />
                    <x-ui.textarea label="Términos de uso" wire:model="settings.terms" rows="3" maxlength="5000" hint="Si hay términos, la persona debe aceptarlos para conectarse" class="sm:col-span-2" />
                    <x-ui.textarea label="Contacto" wire:model="settings.contact" rows="2" maxlength="300" hint="Teléfono, WhatsApp u horario de atención" />
                    <x-ui.input label="Redirigir después de conectar" type="url" wire:model="settings.redirect_url" maxlength="300" placeholder="https://..." hint="Vacío = la página que la persona quería abrir" />

                    <div class="sm:col-span-2">
                        <label class="form-label">Imagen de fondo</label>
                        <div class="flex items-center gap-3">
                            @if ($backgroundImage)
                                <img src="{{ $backgroundImage->temporaryUrl() }}" class="h-14 w-24 rounded-lg object-cover">
                            @elseif ($settings['background_image'])
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings['background_image']) }}" class="h-14 w-24 rounded-lg object-cover">
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" wire:click="removeBackground">Quitar</x-ui.button>
                            @endif
                            <input type="file" wire:model="backgroundImage" accept="image/*" class="text-sm text-slate-600">
                        </div>
                        @error('backgroundImage')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="grid grid-cols-1 gap-2 sm:col-span-2 sm:grid-cols-3">
                        <x-ui.toggle wire:model="settings.show_ads" label="Mostrar publicidad" />
                        <x-ui.toggle wire:model="settings.show_promotions" label="Mostrar promociones" />
                        <x-ui.toggle wire:model="settings.show_plans" label="Mostrar planes" />
                    </div>

                    <div class="flex justify-end sm:col-span-2">
                        <x-ui.button type="submit" icon="check">Guardar configuración</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <div class="space-y-5">
                <x-ui.card title="Conectar con el MikroTik" subtitle="Configuración del hotspot (una sola vez)">
                    <ol class="list-decimal space-y-2 pl-4 text-sm text-slate-600">
                        <li>En el MikroTik: <b>IP → Hotspot → Server Profiles</b>, en <i>Login</i> activa <b>HTTP PAP</b>.</li>
                        <li>En <b>Walled Garden</b> agrega <code class="rounded bg-slate-100 px-1 text-xs">{{ $host }}</code> para que el portal abra sin haber iniciado sesión.</li>
                        <li>Reemplaza el archivo <b>hotspot/login.html</b> del MikroTik por este:</li>
                    </ol>
                    <div class="mt-3" x-data="{ copied: false }">
                        <textarea readonly rows="7" x-ref="html" class="form-input font-mono text-[11px] leading-snug">{{ $loginHtml }}</textarea>
                        <x-ui.button variant="secondary" size="sm" icon="clipboard" class="mt-2" x-on:click="navigator.clipboard?.writeText($refs.html.value); copied = true"><span x-text="copied ? 'Copiado' : 'Copiar login.html'"></span></x-ui.button>
                    </div>
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <x-ui.input label="Nombre DNS del hotspot (opcional)" wire:model="settings.hotspot_dns" filter="host" maxlength="120" placeholder="hotspot.miwisp.net"
                                    hint="Solo si el hotspot usa un DNS name en lugar de una IP privada. Se usa para validar a dónde se envían las credenciales." />
                        <div class="mt-2 flex justify-end"><x-ui.button size="sm" variant="secondary" wire:click="saveSettings">Guardar</x-ui.button></div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Enlace del portal">
                    <div class="flex items-center gap-2" x-data="{ copied: false }">
                        <input readonly value="{{ $portalUrl }}" x-ref="url" class="form-input font-mono text-xs">
                        <x-ui.button variant="secondary" size="sm" icon="clipboard" x-on:click="navigator.clipboard?.writeText($refs.url.value); copied = true"><span x-text="copied ? 'Copiado' : 'Copiar'"></span></x-ui.button>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Para las fichas, el MikroTik debe tener <b>Método de servicio = Hotspot</b> en su ficha de equipo; el sistema crea ahí el usuario de cada ficha al usarse.</p>
                </x-ui.card>
            </div>
        </div>
    @endif

    {{-- ============================================================ Publicidad --}}
    @if ($tab === 'anuncios')
        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat label="Anuncios activos" :value="$adTotals['active']" icon="megaphone" color="brand" variant="soft" />
            <x-ui.stat label="Impresiones" :value="number_format($adTotals['impressions'])" icon="eye" color="teal" variant="soft" />
            <x-ui.stat label="Clics" :value="number_format($adTotals['clicks'])" icon="cursor-arrow-rays" color="purple" variant="soft"
                       :sub="$adTotals['impressions'] ? pct($adTotals['clicks'] / $adTotals['impressions'] * 100, 2).' CTR' : null" />
            <x-ui.stat label="Ingresos por publicidad" :value="money($adTotals['revenue'], false)" icon="banknotes" color="success" variant="soft" sub="Anuncios activos" />
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
            <x-ui.card :padding="false" title="Anuncios">
                <x-slot:actions><x-ui.button size="sm" icon="plus" wire:click="createAd">Nuevo anuncio</x-ui.button></x-slot:actions>
                <x-ui.table>
                    <x-slot:head>
                        <th>Anuncio</th>
                        <th>Vigencia</th>
                        <th class="text-right">Impresiones</th>
                        <th class="text-right">Clics</th>
                        <th class="text-right">Precio</th>
                        <th></th>
                    </x-slot:head>
                    @forelse ($ads as $a)
                        <tr wire:key="ad{{ $a->id }}" @class(['opacity-60' => ! $a->active])>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($a->imageUrl())
                                        <img src="{{ $a->imageUrl() }}" class="h-10 w-10 rounded-lg object-cover">
                                    @else
                                        <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100"><x-heroicon-o-megaphone class="h-5 w-5 text-slate-400" /></span>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-800">{{ $a->title }}</div>
                                        <div class="text-xs text-slate-500">{{ $a->advertiser }} · {{ config('wisp.ad_business_types.'.$a->business_type, $a->business_type) }}@if ($a->campaign) · {{ $a->campaign }}@endif</div>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-xs text-slate-500">
                                {{ $a->starts_at?->format('d/m/Y') ?? 'Siempre' }} – {{ $a->ends_at?->format('d/m/Y') ?? 'sin fin' }}
                                @if ($a->ends_at?->isPast())<div><x-ui.badge size="xs">Vencido</x-ui.badge></div>@endif
                            </td>
                            <td class="text-right">{{ number_format($a->impressions) }}<div class="text-xs text-slate-400">{{ number_format($last30[$a->id]->imp ?? 0) }} en 30 días</div></td>
                            <td class="text-right">{{ number_format($a->clicks) }}<div class="text-xs text-slate-400">{{ pct($a->ctr(), 2) }} CTR</div></td>
                            <td class="text-right">{{ money($a->price, false) }}</td>
                            <td class="whitespace-nowrap text-right">
                                <x-ui.button variant="ghost" size="sm" :icon="$a->active ? 'pause-circle' : 'play-circle'" wire:click="toggleAd({{ $a->id }})" :title="$a->active ? 'Pausar' : 'Activar'" />
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editAd({{ $a->id }})" title="Editar" />
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="megaphone" title="Sin anuncios" description="Vende espacios a comercios, restaurantes o farmacias de la zona." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Últimos 30 días">
                <x-slot:actions>
                    <select wire:model.live="statsAd" class="form-input py-1 text-xs">
                        <option value="">Todos los anuncios</option>
                        @foreach ($ads as $a)<option value="{{ $a->id }}">{{ $a->title }}</option>@endforeach
                    </select>
                </x-slot:actions>
                <x-ui.chart id="ads-chart" :options="$adsChart" height="260px" />
            </x-ui.card>
        </div>

        <x-ui.modal wire:model="showAd" :title="$adId ? 'Editar anuncio' : 'Nuevo anuncio'" size="2xl">
            <form wire:submit="saveAd" id="ad-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Anunciante" wire:model="ad.advertiser" filter="text" maxlength="120" placeholder="Farmacia San José" required />
                <x-ui.select label="Giro" wire:model="ad.business_type" :options="config('wisp.ad_business_types')" required />
                <x-ui.input label="Título" wire:model="ad.title" maxlength="120" placeholder="2x1 en vitaminas" required />
                <x-ui.input label="Campaña" wire:model="ad.campaign" filter="text" maxlength="120" placeholder="Opcional" />
                <x-ui.input label="Texto" wire:model="ad.body" maxlength="190" class="sm:col-span-2" />
                <x-ui.input label="Enlace al hacer clic" type="url" wire:model="ad.url" maxlength="500" placeholder="https://..." hint="Agrégalo también al Walled Garden si debe abrir sin iniciar sesión" class="sm:col-span-2" />
                <x-ui.input label="Inicio" type="date" wire:model="ad.starts_at" />
                <x-ui.input label="Fin" type="date" wire:model="ad.ends_at" />
                <x-ui.input label="Peso" type="number" min="1" max="100" wire:model="ad.weight" hint="Más peso = se muestra más seguido" />
                <x-ui.input label="Precio cobrado" type="number" step="0.01" min="0" max="1000000" prefix="$" wire:model="ad.price" />
                <div class="sm:col-span-2">
                    <label class="form-label">Imagen</label>
                    <input type="file" wire:model="adImage" accept="image/*" class="text-sm text-slate-600">
                    @error('adImage')<p class="mt-1 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                </div>
                <x-ui.toggle wire:model="ad.active" label="Anuncio activo" class="sm:col-span-2" />
            </form>
            <x-slot:footer>
                @if ($adId)
                    <x-ui.button variant="ghost" class="mr-auto text-danger-600" icon="trash" wire:click="deleteAd({{ $adId }})" wire:confirm="¿Eliminar este anuncio y sus estadísticas?">Eliminar</x-ui.button>
                @endif
                <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
                <x-ui.button type="submit" form="ad-form" icon="check">Guardar</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    {{-- ============================================================ Fichas --}}
    @if ($tab === 'vouchers')
        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat label="Disponibles" :value="number_format($voucherCounts['available'] ?? 0)" icon="ticket" color="success" variant="soft" />
            <x-ui.stat label="Usadas" :value="number_format($voucherCounts['used'] ?? 0)" icon="check-circle" color="brand" variant="soft" />
            <x-ui.stat label="Expiradas" :value="number_format($voucherCounts['expired'] ?? 0)" icon="clock" color="slate" variant="soft" />
            <x-ui.stat label="Vendido (fichas usadas)" :value="money($soldValue, false)" icon="banknotes" color="purple" variant="soft" />
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
            <x-ui.card title="Generar fichas">
                <form wire:submit="generateVouchers" class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Cantidad" type="number" min="1" max="500" wire:model="gen.quantity" required />
                    <x-ui.input label="Precio" type="number" step="0.01" min="0" max="100000" prefix="$" wire:model="gen.price" />
                    <x-ui.select label="Duración" wire:model="gen.duration_minutes" :options="['30' => '30 minutos', '60' => '1 hora', '180' => '3 horas', '1440' => '1 día', '10080' => '1 semana', '43200' => '30 días']" required />
                    <x-ui.input label="Límite de datos" type="number" min="1" max="10000000" suffix="MB" wire:model="gen.data_limit_mb" placeholder="Sin límite" />
                    <x-ui.input label="Vigencia para usarse" type="number" min="1" max="3650" suffix="días" wire:model="gen.expires_days" />
                    <x-ui.input label="Lote" wire:model="gen.batch" filter="code" maxlength="40" required />
                    <x-ui.button type="submit" icon="sparkles" class="col-span-2">Generar</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
                    <x-ui.search wire:model.live.debounce.300ms="voucherSearch" placeholder="Código o MAC..." class="md:col-span-2" />
                    <x-ui.select wire:model.live="voucherStatus" :options="collect(\App\Models\CaptiveVoucher::STATUS)->map(fn ($s) => $s[0])->all()" placeholder="Todos los estados" />
                    <x-ui.select wire:model.live="voucherBatch" :options="$batches->all()" placeholder="Todos los lotes" />
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2">
                    <x-ui.button variant="ghost" size="sm" icon="clock" wire:click="expireOverdue">Expirar vencidas</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" icon="printer" x-on:click="window.print()">Imprimir</x-ui.button>
                </div>
                <x-ui.table>
                    <x-slot:head>
                        <th>Código</th>
                        <th>Lote</th>
                        <th>Duración</th>
                        <th class="text-right">Precio</th>
                        <th>Estado</th>
                        <th>Uso</th>
                        <th></th>
                    </x-slot:head>
                    @forelse ($vouchers as $v)
                        @php [$vLabel, $vColor] = \App\Models\CaptiveVoucher::STATUS[$v->status] ?? [$v->status, 'slate']; @endphp
                        <tr wire:key="v{{ $v->id }}">
                            <td class="font-mono font-bold tracking-wider text-navy-900">{{ $v->code }}</td>
                            <td class="text-xs text-slate-500">{{ $v->batch }}</td>
                            <td class="text-sm text-slate-600">
                                {{ $v->duration_minutes >= 1440 ? round($v->duration_minutes / 1440, 1).' día(s)' : ($v->duration_minutes >= 60 ? round($v->duration_minutes / 60, 1).' h' : $v->duration_minutes.' min') }}
                                @if ($v->data_limit_mb)<div class="text-xs text-slate-400">{{ number_format($v->data_limit_mb) }} MB</div>@endif
                            </td>
                            <td class="text-right">{{ money($v->price) }}</td>
                            <td><x-ui.badge :color="$vColor" dot>{{ $vLabel }}</x-ui.badge></td>
                            <td class="text-xs text-slate-500">
                                @if ($v->used_at){{ $v->used_at->format('d/m/Y H:i') }}<div class="font-mono text-slate-400">{{ $v->mac }}</div>
                                @elseif ($v->expires_at)Vence {{ $v->expires_at->format('d/m/Y') }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-right">
                                @if ($v->status === 'available')
                                    <x-ui.button variant="ghost" size="sm" icon="no-symbol" wire:click="expireVoucher({{ $v->id }})" wire:confirm="¿Anular la ficha {{ $v->code }}?" title="Anular" />
                                @endif
                                @if ($v->status !== 'used')
                                    <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" wire:click="deleteVoucher({{ $v->id }})" wire:confirm="¿Eliminar la ficha {{ $v->code }}?" title="Eliminar" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="7" icon="ticket" title="Sin fichas" description="Genera un lote para venderlas en tiendas de la zona." />
                    @endforelse
                </x-ui.table>
                <div class="border-t border-slate-100 px-4 py-3">{{ $vouchers->links() }}</div>
            </x-ui.card>
        </div>
    @endif

    {{-- ============================================================ Sesiones --}}
    @if ($tab === 'sesiones')
        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-ui.stat label="Accesos hoy" :value="number_format($sessionStats['today'])" icon="wifi" color="brand" variant="soft" />
            <x-ui.stat label="Accesos (30 días)" :value="number_format($sessionStats['total30'])" icon="arrow-right-end-on-rectangle" color="teal" variant="soft" />
            <x-ui.stat label="Exitosos" :value="pct($sessionStats['success'])" icon="check-circle" color="success" variant="soft" />
            <x-ui.stat label="Dispositivos únicos" :value="number_format($sessionStats['macs'])" icon="device-phone-mobile" color="purple" variant="soft" sub="Últimos 30 días" />
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
            <x-ui.card :padding="false">
                <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-2">
                    <x-ui.select wire:model.live="sessionMethod" :options="collect(config('wisp.growth.captive_session_methods'))->map(fn ($m) => $m[0])->all()" placeholder="Todos los métodos" />
                    <x-ui.select wire:model.live="sessionResult" :options="['1' => 'Exitosos', '0' => 'Rechazados']" placeholder="Todos los resultados" />
                </div>
                <x-ui.table>
                    <x-slot:head>
                        <th>Fecha</th>
                        <th>Método</th>
                        <th>Identificador</th>
                        <th>Cliente</th>
                        <th>Dispositivo</th>
                        <th>Resultado</th>
                    </x-slot:head>
                    @forelse ($sessions as $s)
                        @php [$mLabel, $mColor] = config('wisp.growth.captive_session_methods.'.$s->method, [$s->method, 'slate']); @endphp
                        <tr wire:key="s{{ $s->id }}">
                            <td class="whitespace-nowrap text-xs text-slate-500">{{ $s->created_at->format('d/m/Y H:i') }}</td>
                            <td><x-ui.badge :color="$mColor" size="xs">{{ $mLabel }}</x-ui.badge></td>
                            <td class="font-mono text-xs text-slate-600">{{ $s->identifier ?? '—' }}</td>
                            <td class="text-sm">
                                @if ($s->client)<a href="{{ route('clients.show', $s->client) }}" wire:navigate class="link">{{ $s->client->code }} · {{ $s->client->first_name }}</a>@else<span class="text-slate-400">—</span>@endif
                            </td>
                            <td class="font-mono text-xs text-slate-500">{{ $s->mac ?? '—' }}<div class="text-slate-400">{{ $s->ip }}</div></td>
                            <td><x-ui.badge :color="$s->success ? 'success' : 'danger'" dot>{{ $s->success ? 'Exitoso' : 'Rechazado' }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="6" icon="wifi" title="Sin sesiones" description="Aquí aparecen los accesos al portal." />
                    @endforelse
                </x-ui.table>
                <div class="border-t border-slate-100 px-4 py-3">{{ $sessions->links() }}</div>
            </x-ui.card>

            <x-ui.card title="Accesos por día" subtitle="Últimos 30 días">
                <x-ui.chart id="sessions-chart" :options="$sessionsChart" height="260px" />
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (config('wisp.growth.captive_session_methods') as $key => [$label, $color])
                        <x-ui.badge :color="$color">{{ $label }}: {{ number_format($byMethod[$key] ?? 0) }}</x-ui.badge>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    @endif
</div>
