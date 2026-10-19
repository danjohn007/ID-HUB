<div>
    <x-ui.page-header title="Configuración" subtitle="Datos de tu empresa, políticas de cobranza, pagos en línea, mensajería y parámetros de operación" />

    <x-ui.tabs wire:model.live="tab" :tabs="\App\Livewire\Settings\Index::TABS" class="mb-5" />

    {{-- ===================================================== EMPRESA --}}
    @if ($tab === 'empresa')
        <form wire:submit="saveCompany" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Datos fiscales y comerciales">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-ui.input label="Nombre comercial" wire:model="company.name" filter="text" maxlength="150" required />
                        <x-ui.input label="Razón social" wire:model="company.legal_name" filter="text" maxlength="190" />
                        <x-ui.input label="RFC" wire:model="company.rfc" filter="rfc" placeholder="ABC123456XY9" class="uppercase" />
                        <x-ui.select label="Régimen fiscal" wire:model="company.tax_regime" :options="config('wisp.tax_regimes')" placeholder="—" />
                        <x-ui.input label="Dirección" wire:model="company.address" filter="text" maxlength="190" class="md:col-span-2" />
                        <x-ui.input label="Teléfono" type="tel" wire:model="company.phone" filter="phone" />
                        <x-ui.input label="WhatsApp de atención" type="tel" wire:model="company.whatsapp" filter="phone" hint="Se muestra a los clientes en mensajes y en la app" />
                        <x-ui.input label="Correo electrónico" type="email" wire:model="company.email" filter="email" class="md:col-span-2" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Dominio, moneda y zona horaria">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-ui.input label="Subdominio" wire:model="company.subdomain" filter="slug" suffix=".wispcontrol.com" placeholder="mi-wisp" />
                        <x-ui.input label="Dominio propio (opcional)" wire:model="company.domain" filter="host" maxlength="150" placeholder="portal.miwisp.mx" hint="Apunta un registro CNAME a la plataforma" />
                        <x-ui.select label="Moneda" wire:model="company.currency" :options="config('wisp.currencies')" required />
                        <x-ui.select label="Zona horaria" wire:model="company.timezone" :options="config('wisp.timezones')" required hint="Se usa para programar cortes y recordatorios" />
                        <x-ui.input label="Centro del mapa · latitud" wire:model="company.map_lat" filter="signed" placeholder="20.7350" />
                        <x-ui.input label="Centro del mapa · longitud" wire:model="company.map_lng" filter="signed" placeholder="-100.4480" />
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-5">
                <x-ui.card title="Logotipo" subtitle="PNG, JPG o WEBP de hasta 2 MB. Aparece en recibos, contratos y la app.">
                    <div class="flex items-center gap-4">
                        <div class="flex h-20 w-32 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50">
                            @if ($logo && method_exists($logo, 'isPreviewable') && $logo->isPreviewable())
                                <img src="{{ $logo->temporaryUrl() }}" class="max-h-16 max-w-28 object-contain" alt="Vista previa">
                            @elseif ($tenant->logoUrl())
                                <img src="{{ $tenant->logoUrl() }}" class="max-h-16 max-w-28 object-contain" alt="Logotipo">
                            @else
                                <x-heroicon-o-photo class="h-8 w-8 text-slate-300" />
                            @endif
                        </div>
                        <div class="space-y-2">
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50">
                                <x-heroicon-m-arrow-up-tray class="h-4 w-4" /> Subir imagen
                                <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                            </label>
                            @if ($tenant->logo_path)
                                <button type="button" wire:click="removeLogo" wire:confirm="¿Eliminar el logotipo?" class="block text-xs font-semibold text-danger-600 hover:underline">Quitar logotipo</button>
                            @endif
                            <div wire:loading wire:target="logo" class="text-xs text-brand-600">Subiendo…</div>
                        </div>
                    </div>
                    @error('logo')<p class="mt-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                </x-ui.card>

                <x-ui.card title="Colores corporativos">
                    @unless ($whiteLabel)
                        <div class="mb-3 flex gap-2 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700">
                            <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0" /> La personalización de colores (marca blanca) está disponible en el plan Enterprise.
                        </div>
                    @endunless
                    <div class="grid grid-cols-2 gap-4">
                        @foreach (['primary_color' => 'Color primario', 'secondary_color' => 'Color secundario'] as $key => $label)
                            <div>
                                <label class="form-label">{{ $label }}</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" wire:model.live="company.{{ $key }}" @disabled(! $whiteLabel) class="h-9 w-11 cursor-pointer rounded-lg border border-slate-300 bg-white p-1 disabled:cursor-not-allowed disabled:opacity-50">
                                    <input type="text" wire:model.blur="company.{{ $key }}" data-filter="color" maxlength="7" @disabled(! $whiteLabel) class="form-input font-mono text-xs uppercase">
                                </div>
                                @error('company.'.$key)<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex overflow-hidden rounded-lg border border-slate-200 text-xs font-semibold text-white">
                        <div class="flex-1 px-3 py-2" style="background: {{ $company['primary_color'] }}">Primario</div>
                        <div class="flex-1 px-3 py-2" style="background: {{ $company['secondary_color'] }}">Secundario</div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Suscripción">
                    <dl class="grid grid-cols-2 gap-3">
                        <x-ui.detail label="Plan ID-Hub">{{ $tenant->saasPlan?->name ?? 'Sin plan' }}</x-ui.detail>
                        <x-ui.detail label="Vigencia">{{ $tenant->subscription_ends_at?->format('d/m/Y') ?? ($tenant->trial_ends_at ? 'Prueba hasta '.$tenant->trial_ends_at->format('d/m/Y') : '—') }}</x-ui.detail>
                        @foreach (['clients' => 'Clientes', 'devices' => 'Equipos MikroTik', 'users' => 'Usuarios'] as $res => $lbl)
                            @php $lim = $tenant->planLimit($res); $use = $tenant->usage($res); @endphp
                            <div class="col-span-2">
                                <div class="mb-1 flex justify-between text-xs"><span class="text-slate-500">{{ $lbl }}</span><span class="font-semibold text-slate-700">{{ number_format($use) }} / {{ $lim ? number_format($lim) : '∞' }}</span></div>
                                <x-ui.progress :value="$lim ? $use / $lim * 100 : 0" />
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>

                <x-ui.button type="submit" icon="check" class="w-full py-2.5">Guardar datos de la empresa</x-ui.button>
            </div>
        </form>
    @endif

    {{-- ===================================================== COBRANZA --}}
    @if ($tab === 'cobranza')
        <form wire:submit="saveBilling" class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-ui.card title="Facturación" subtitle="Cuándo se generan las mensualidades y cuánto tiempo tiene el cliente para pagar">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-ui.select label="Modo de corte" wire:model.live="billing.mode" :options="['fixed_day' => 'Día fijo para todos', 'anniversary' => 'Aniversario de cada cliente']" required />
                        <x-ui.input label="Día de corte" type="number" min="1" max="28" wire:model="billing.billing_day" :hint="$billing['mode'] === 'anniversary' ? 'Valor por defecto para clientes nuevos' : 'Día del mes en que se emiten los cargos'" required />
                        <x-ui.input label="Días para pagar" type="number" min="0" max="30" wire:model="billing.due_days" hint="Entre la emisión y la fecha límite" suffix="días" required />
                        <x-ui.input label="Recordatorio previo" type="number" min="0" max="15" wire:model="billing.reminder_days_before" hint="Días antes de la fecha límite" suffix="días" required />
                        <x-ui.input label="Prefijo de folios" wire:model="billing.folio_prefix" filter="upper" maxlength="10" hint="Recibos: REC-000123" required />
                    </div>
                </x-ui.card>

                <x-ui.card title="Política de corte" subtitle="Fecha límite vencida → periodo de gracia → suspensión → MikroTik bloquea el servicio">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <x-ui.input label="Días de tolerancia" type="number" min="0" max="60" wire:model="billing.grace_days" suffix="días" required />
                        <x-ui.input label="Hora de corte" type="time" wire:model="billing.cut_hour" required />
                        <x-ui.select label="Tipo de bloqueo" wire:model="billing.block_type" :options="['partial' => 'Parcial (portal de aviso / velocidad mínima)', 'total' => 'Total (sin navegación)']" required />
                        <x-ui.input label="Recargo por pago tardío" type="number" step="0.01" min="0" max="100000" wire:model="billing.late_fee" prefix="$" required />
                        <x-ui.input label="Cargo por reconexión" type="number" step="0.01" min="0" max="100000" wire:model="billing.reconnection_fee" prefix="$" required />
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-5">
                <x-ui.card title="Automatización">
                    <div class="space-y-4">
                        <x-ui.toggle wire:model="billing.auto_suspend" label="Corte automático" description="Suspende en MikroTik al superar la tolerancia, aplica recargo y avisa por WhatsApp." />
                        <x-ui.toggle wire:model="billing.auto_reconnect" label="Reconexión automática" description="Reactiva el servicio en cuanto se registra el pago." />
                        <x-ui.toggle wire:model="billing.reconnect_requires_full_payment" label="Exigir liquidar el saldo" description="Solo reconecta si el cliente queda sin adeudo." />
                    </div>
                    <div class="mt-4 space-y-1.5 border-t border-slate-100 pt-3 text-xs">
                        <div class="font-semibold text-slate-500">Reglas del motor de automatización</div>
                        @foreach ([\App\Livewire\Settings\Index::RULE_SUSPEND, \App\Livewire\Settings\Index::RULE_RECONNECT] as $rule)
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-slate-600">{{ $rule }}</span>
                                @if (! isset($rules[$rule]))
                                    <x-ui.badge color="slate" size="xs">No existe</x-ui.badge>
                                @else
                                    <x-ui.badge :color="$rules[$rule] ? 'success' : 'slate'" size="xs">{{ $rules[$rule] ? 'Activa' : 'Inactiva' }}</x-ui.badge>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
                <x-ui.button type="submit" icon="check" class="w-full py-2.5">Guardar políticas</x-ui.button>
            </div>
        </form>
    @endif

    {{-- ===================================================== PASARELAS --}}
    @if ($tab === 'pasarelas')
        <div class="mb-4 flex gap-2 rounded-lg bg-brand-50 px-4 py-3 text-sm text-brand-800">
            <x-heroicon-o-shield-check class="h-5 w-5 shrink-0" />
            <span>Las credenciales se guardan cifradas y nunca se muestran completas. Deja un campo vacío para conservar el valor actual.
                La comisión de ID-Hub de tu plan es {{ pct($tenant->saasPlan?->commission_percent ?? 0, 2) }}{{ ($tenant->saasPlan?->commission_fixed ?? 0) > 0 ? ' + '.money($tenant->saasPlan->commission_fixed) : '' }} por pago en línea.</span>
        </div>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            @foreach (config('wisp.gateways') as $p => $label)
                <x-ui.card wire:key="gw-{{ $p }}">
                    <x-slot:title>{{ $label }}</x-slot:title>
                    <x-slot:actions>
                        <x-ui.badge :color="$gateways[$p]['enabled'] ? 'success' : 'slate'" dot>{{ $gateways[$p]['enabled'] ? 'Habilitada' : 'Deshabilitada' }}</x-ui.badge>
                        @if ($gateways[$p]['sandbox'])<x-ui.badge color="warning">Sandbox</x-ui.badge>@endif
                    </x-slot:actions>
                    <form wire:submit="saveGateway('{{ $p }}')" class="space-y-4">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <x-ui.toggle wire:model="gateways.{{ $p }}.enabled" label="Habilitar" description="Ofrecer en el enlace “Pagar ahora”" />
                            <x-ui.toggle wire:model="gateways.{{ $p }}.sandbox" label="Modo sandbox" description="Pruebas sin cobros reales" />
                        </div>
                        @error("gateways.$p.enabled")<p class="text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.input label="Comisión del gateway" type="number" step="0.01" min="0" max="20" wire:model="gateways.{{ $p }}.fee_percent" suffix="%" />
                            <x-ui.input label="Cargo fijo por operación" type="number" step="0.01" min="0" max="1000" wire:model="gateways.{{ $p }}.fee_fixed" prefix="$" />
                        </div>
                        @if ($p === 'oxxo')
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">OXXO Pay se procesa a través de Stripe y utiliza sus credenciales. Configura primero Stripe.</p>
                        @else
                            <div class="grid grid-cols-1 gap-3">
                                @foreach (\App\Livewire\Settings\Index::GATEWAY_FIELDS[$p] as $field => [$flabel, $secret])
                                    <x-ui.input :label="$flabel" :type="$secret ? 'password' : 'text'" autocomplete="off" maxlength="500" wire:model="credentials.{{ $p }}.{{ $field }}"
                                                :placeholder="$masked[$p][$field] ? 'Guardado: '.$masked[$p][$field] : 'Sin configurar'" />
                                @endforeach
                            </div>
                        @endif
                        <div>
                            <label class="form-label">URL de webhook</label>
                            <div class="flex items-center gap-2" x-data="{ copied: false }">
                                <input readonly value="{{ url('/webhooks/'.($p === 'oxxo' ? 'stripe' : $p)) }}" class="form-input bg-slate-50 font-mono text-xs" x-ref="u">
                                <x-ui.button variant="secondary" size="sm" icon="clipboard" x-on:click="navigator.clipboard?.writeText($refs.u.value); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-text="copied ? 'Copiado' : 'Copiar'"></span>
                                </x-ui.button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                            @if ($hasCredentials[$p] ?? false)
                                <button type="button" wire:click="clearCredentials('{{ $p }}')" wire:confirm="¿Eliminar las credenciales de {{ $label }}? La pasarela se deshabilitará." class="text-xs font-semibold text-danger-600 hover:underline">Eliminar credenciales</button>
                            @else
                                <span></span>
                            @endif
                            <x-ui.button type="submit" size="sm" icon="check">Guardar {{ $label }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    {{-- ===================================================== MENSAJERÍA --}}
    @if ($tab === 'mensajeria')
        <form wire:submit="saveMessaging" class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <x-ui.card title="WhatsApp" subtitle="Avisos de cobranza, suspensión, pagos y soporte">
                <div class="space-y-4">
                    <x-ui.select label="Proveedor" wire:model.live="messaging.whatsapp_provider" :options="['simulated' => 'Simulado (demo, no envía)', 'meta' => 'WhatsApp Cloud API (Meta)', 'twilio' => 'Twilio WhatsApp']" />
                    @if ($messaging['whatsapp_provider'] === 'meta')
                        <x-ui.input label="Phone number ID" wire:model="messaging.whatsapp_phone_id" filter="digits" maxlength="80" />
                        <x-ui.input label="Token de acceso (permanente)" type="password" autocomplete="off" maxlength="1000" wire:model="secrets.whatsapp_token" :placeholder="$maskedSecrets['whatsapp_token'] ? 'Guardado: '.$maskedSecrets['whatsapp_token'] : 'Sin configurar'" />
                        <x-ui.select label="Idioma de las plantillas" wire:model="messaging.whatsapp_language" :options="\App\Livewire\Settings\Index::WHATSAPP_LANGUAGES" />
                        <x-ui.input label="Secreto de la app (App secret)" type="password" autocomplete="off" maxlength="200" wire:model="secrets.whatsapp_app_secret" :placeholder="$maskedSecrets['whatsapp_app_secret'] ? 'Guardado: '.$maskedSecrets['whatsapp_app_secret'] : 'Sin configurar'" />
                        <div class="space-y-2 rounded-lg bg-slate-50 p-3 text-xs" x-data="{ copied: null, copy(k, v) { navigator.clipboard.writeText(v); this.copied = k; setTimeout(() => this.copied = null, 1500) } }">
                            <div class="font-semibold text-slate-700">Webhook (estado de entrega)</div>
                            <p class="text-slate-500">En Meta → tu app → WhatsApp → Configuración → Webhook, captura estos datos y suscríbete al campo <b>messages</b>.</p>
                            <div class="flex items-center gap-2">
                                <span class="w-28 shrink-0 text-slate-500">URL de devolución</span>
                                <code class="min-w-0 flex-1 break-all text-slate-800">{{ $metaWebhook['url'] }}</code>
                                <button type="button" class="font-semibold text-brand-700 hover:underline" @click="copy('url', @js($metaWebhook['url']))" x-text="copied === 'url' ? 'Copiado' : 'Copiar'"></button>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-28 shrink-0 text-slate-500">Token de verificación</span>
                                @if ($metaWebhook['verify_token'])
                                    <code class="min-w-0 flex-1 break-all text-slate-800">{{ $metaWebhook['verify_token'] }}</code>
                                    <button type="button" class="font-semibold text-brand-700 hover:underline" @click="copy('token', @js($metaWebhook['verify_token']))" x-text="copied === 'token' ? 'Copiado' : 'Copiar'"></button>
                                @else
                                    <span class="text-warning-700">Se genera al guardar la mensajería.</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <x-ui.button type="button" variant="secondary" size="sm" icon="signal" wire:click="testMeta" wire:loading.attr="disabled" wire:target="testMeta">Probar conexión</x-ui.button>
                        </div>
                    @elseif ($messaging['whatsapp_provider'] === 'twilio')
                        <x-ui.input label="Número de WhatsApp (Twilio)" wire:model="messaging.twilio_whatsapp_from" filter="phone" placeholder="+14155238886" />
                        <p class="text-xs text-slate-500">Usa las credenciales de Twilio de la sección SMS.</p>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="SMS (Twilio)">
                <div class="space-y-4">
                    <x-ui.select label="Proveedor de SMS" wire:model.live="messaging.sms_provider" :options="['simulated' => 'Simulado (demo, no envía)', 'twilio' => 'Twilio']" />
                    @if ($messaging['sms_provider'] === 'twilio' || $messaging['whatsapp_provider'] === 'twilio')
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-ui.input label="Account SID" wire:model="messaging.twilio_sid" filter="code" maxlength="80" />
                            <x-ui.input label="Auth token" type="password" autocomplete="off" maxlength="200" wire:model="secrets.twilio_token" :placeholder="$maskedSecrets['twilio_token'] ? 'Guardado: '.$maskedSecrets['twilio_token'] : 'Sin configurar'" />
                            <x-ui.input label="Número remitente SMS" wire:model="messaging.twilio_from" filter="phone" placeholder="+15005550006" class="md:col-span-2" />
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="min-w-0 break-all text-xs text-slate-500">El estado de entrega (Entregado / Fallido) se actualiza solo: Twilio avisa a {{ \App\Services\Messaging\MessageService::twilioStatusUrl() ?? 'este panel cuando esté publicado con https' }}.</p>
                            <x-ui.button type="button" variant="secondary" size="sm" icon="signal" wire:click="testTwilio" wire:loading.attr="disabled" wire:target="testTwilio">Probar credenciales</x-ui.button>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card title="Correo y notificaciones push">
                <div class="space-y-4">
                    <x-ui.toggle wire:model="messaging.email_enabled" label="Enviar correos electrónicos" description="Recordatorios, recibos y avisos al correo del cliente" />
                    <x-ui.select label="Notificaciones push (app del cliente)" wire:model="messaging.push_provider" :options="['simulated' => 'Simulado (demo)', 'expo' => 'Expo Push (apps móviles)']" />
                </div>
            </x-ui.card>

            <x-ui.card title="Canales predeterminados" subtitle="Por dónde se envían los avisos automáticos">
                <div class="grid grid-cols-2 gap-3">
                    @foreach (config('wisp.channels') as $ch => $chLabel)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                            <input type="checkbox" value="{{ $ch }}" wire:model="messaging.default_channels" class="rounded border-slate-300 text-brand-600">
                            {{ $chLabel }}
                        </label>
                    @endforeach
                </div>
                @error('messaging.default_channels')<p class="mt-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
                <x-ui.button type="submit" icon="check" class="mt-5 w-full">Guardar mensajería</x-ui.button>
            </x-ui.card>
        </form>
    @endif

    {{-- ===================================================== RED --}}
    @if ($tab === 'red')
        <form wire:submit="saveNetwork" class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <x-ui.card title="Umbrales de monitoreo" subtitle="Generan alertas de señal, latencia, pérdida de paquetes y anomalías">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input label="Señal mínima aceptable" type="number" step="1" min="-100" max="-30" wire:model="network.signal_min_dbm" suffix="dBm" hint="Por debajo se marca señal anormal" />
                    <x-ui.input label="Latencia máxima" type="number" min="1" max="5000" wire:model="network.latency_max_ms" suffix="ms" />
                    <x-ui.input label="Pérdida de paquetes máxima" type="number" step="0.1" min="0" max="100" wire:model="network.packet_loss_max" suffix="%" />
                    <x-ui.input label="Desconexión masiva" type="number" min="1" max="10000" wire:model="network.mass_disconnect_threshold" suffix="clientes" hint="Clientes caídos a la vez para alerta" />
                    <x-ui.input label="Factor de consumo anormal" type="number" step="0.5" min="1" max="50" wire:model="network.usage_anomaly_factor" suffix="× promedio" />
                </div>
            </x-ui.card>
            <x-ui.card title="Parámetros de expansión (BI)" subtitle="Usados en Business Intelligence → Análisis de expansión">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input label="Inversión de un repetidor nuevo" type="number" step="100" min="0" max="10000000" wire:model="bi.repeater_cost_estimate" prefix="$" />
                    <x-ui.input label="Ampliar repetidor existente" type="number" step="100" min="0" max="10000000" wire:model="bi.sector_cost_estimate" prefix="$" hint="Nuevo sector o antena" />
                    <x-ui.input label="Conversión esperada de prospectos" type="number" min="1" max="100" wire:model="bi.expected_conversion" suffix="%" />
                    <x-ui.input label="Radio de agrupación de demanda" type="number" step="0.1" min="0.3" max="5" wire:model="bi.cluster_km" suffix="km" />
                </div>
                <x-ui.button type="submit" icon="check" class="mt-5 w-full">Guardar parámetros</x-ui.button>
            </x-ui.card>
        </form>
    @endif

    {{-- ===================================================== CONTRATO --}}
    @if ($tab === 'contrato')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <x-ui.card title="Plantilla del contrato" subtitle="Se usa al generar el contrato PDF de cada cliente">
                <div class="mb-3 flex flex-wrap gap-1.5">
                    @foreach (\App\Livewire\Settings\Index::CONTRACT_VARS as $v)
                        <button type="button" class="rounded-md bg-brand-50 px-2 py-0.5 font-mono text-xs text-brand-700 hover:bg-brand-100"
                                x-on:click="const t = $root.querySelector('textarea'); const s = t.selectionStart; t.setRangeText('{{ '{'.$v.'}' }}', s, t.selectionEnd, 'end'); t.dispatchEvent(new Event('input')); t.focus()">{{ '{'.$v.'}' }}</button>
                    @endforeach
                </div>
                <x-ui.textarea wire:model.live.debounce.600ms="contract" rows="18" maxlength="20000" class="font-mono text-xs" />
                <div class="mt-4 flex flex-wrap justify-between gap-2">
                    <x-ui.button variant="ghost" icon="arrow-uturn-left" wire:click="restoreContract">Plantilla original</x-ui.button>
                    <x-ui.button icon="check" wire:click="saveContract">Guardar plantilla</x-ui.button>
                </div>
            </x-ui.card>
            <x-ui.card title="Vista previa" subtitle="Con datos de ejemplo">
                <div class="max-h-[560px] overflow-y-auto rounded-lg border border-slate-200 bg-white p-5 text-sm leading-relaxed whitespace-pre-line text-slate-700">{{ $contractPreview }}</div>
            </x-ui.card>
        </div>
    @endif

    {{-- ===================================================== COMISIONES --}}
    @if ($tab === 'comisiones')
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <x-ui.card title="Comisiones de tu personal">
                <form wire:submit="saveCommissions" class="space-y-4">
                    <x-ui.input label="Comisión del cobrador" type="number" step="0.1" min="0" max="100" wire:model="commissions.collector_percent" suffix="% de lo cobrado en campo" />
                    <x-ui.input label="Pago al técnico por instalación" type="number" step="1" min="0" max="100000" wire:model="commissions.technician_install_fee" prefix="$" />
                    <x-ui.button type="submit" icon="check">Guardar comisiones</x-ui.button>
                </form>
            </x-ui.card>
            <x-ui.card title="Comisiones por pagos en línea" subtitle="Se descuentan de cada pago recibido por pasarela">
                <dl class="grid grid-cols-2 gap-4">
                    <x-ui.detail label="Comisión ID-Hub ({{ $tenant->saasPlan?->name ?? 'sin plan' }})">
                        {{ pct($tenant->saasPlan?->commission_percent ?? 0, 2) }}{{ ($tenant->saasPlan?->commission_fixed ?? 0) > 0 ? ' + '.money($tenant->saasPlan->commission_fixed) : '' }}
                    </x-ui.detail>
                    <x-ui.detail label="Comisiones de pasarelas">Configurables en “Pasarelas de pago”</x-ui.detail>
                </dl>
                <div class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                    Ejemplo: pago de {{ money(450) }} con Mercado Pago ({{ pct($gateways['mercadopago']['fee_percent'] ?? 0, 2) }} + {{ money($gateways['mercadopago']['fee_fixed'] ?? 0) }}):
                    @php
                        $gw = round(450 * (float) ($gateways['mercadopago']['fee_percent'] ?? 0) / 100 + (float) ($gateways['mercadopago']['fee_fixed'] ?? 0), 2);
                        $pf = app(\App\Services\BillingService::class)->platformFee($tenant, 450);
                    @endphp
                    comisión gateway {{ money($gw) }}, comisión plataforma {{ money($pf) }}, <b>neto {{ money(450 - $gw - $pf) }}</b>.
                </div>
                @if (Route::has('finance.index'))
                    <x-ui.button variant="secondary" size="sm" icon="chart-bar" class="mt-4" :href="route('finance.index')">Ver comisiones acumuladas</x-ui.button>
                @endif
            </x-ui.card>
        </div>
    @endif

    {{-- ===================================================== ZONAS --}}
    @if ($tab === 'zonas')
        <x-ui.card :padding="false">
            <x-slot:title>Zonas de operación</x-slot:title>
            <x-slot:actions><x-ui.button size="sm" icon="plus" wire:click="newZone">Nueva zona</x-ui.button></x-slot:actions>
            <x-ui.table>
                <x-slot:head><th>Zona</th><th>Comunidad</th><th>Centro</th><th class="text-right">Clientes</th><th class="text-right">Repetidores</th><th class="text-right">Torres</th><th class="text-right">Costo mensual</th><th></th></x-slot:head>
                @forelse ($zones as $z)
                    <tr wire:key="zone{{ $z->id }}">
                        <td><span class="flex items-center gap-2 font-semibold text-slate-800"><span class="h-3 w-3 rounded-full" style="background: {{ $z->color }}"></span>{{ $z->name }}</span></td>
                        <td class="text-sm text-slate-600">{{ $z->community ?: '—' }}</td>
                        <td class="font-mono text-xs text-slate-500">{{ $z->lat ? number_format($z->lat, 4).', '.number_format($z->lng, 4) : '—' }}</td>
                        <td class="text-right">{{ number_format($z->clients_count) }}</td>
                        <td class="text-right">{{ $z->repeaters_count }}</td>
                        <td class="text-right">{{ $z->towers_count }}</td>
                        <td class="text-right font-semibold">{{ money($z->monthly_cost) }}</td>
                        <td class="text-right whitespace-nowrap">
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editZone({{ $z->id }})" title="Editar" />
                            <x-ui.button variant="ghost" size="sm" icon="trash" wire:click="deleteZone({{ $z->id }})" wire:confirm="¿Eliminar la zona {{ $z->name }}?" title="Eliminar" />
                        </td>
                    </tr>
                @empty
                    <x-ui.empty :colspan="8" icon="map" title="Sin zonas" description="Crea zonas para organizar clientes, repetidores y analizar su rentabilidad." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.modal wire:model="showZone" :title="$zoneId ? 'Editar zona' : 'Nueva zona'">
            <form wire:submit="saveZone" id="zone-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Nombre" wire:model="zone.name" filter="text" maxlength="120" required class="sm:col-span-2" />
                <x-ui.input label="Comunidad" wire:model="zone.community" filter="text" maxlength="120" />
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" wire:model="zone.color" class="h-9 w-full cursor-pointer rounded-lg border border-slate-300 bg-white p-1">
                </div>
                <x-ui.input label="Latitud del centro" wire:model="zone.lat" filter="signed" />
                <x-ui.input label="Longitud del centro" wire:model="zone.lng" filter="signed" />
                <x-ui.input label="Costo operativo mensual" type="number" step="0.01" min="0" max="10000000" wire:model="zone.monthly_cost" prefix="$" hint="Renta, enlace, energía, personal… (para BI)" class="sm:col-span-2" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
                <x-ui.button type="submit" form="zone-form" icon="check">Guardar zona</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
