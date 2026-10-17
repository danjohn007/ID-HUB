<?php

/*
|--------------------------------------------------------------------------
| Catálogos y valores por defecto de ID-Hub
|--------------------------------------------------------------------------
| Etiquetas y colores de estados (usar siempre estos catálogos en vistas),
| y configuración por defecto de cada WISP (se fusiona con tenants.settings).
| Colores: primary, success, danger, warning, info, purple, slate, teal.
*/

return [

    // Instalador web (/install). Desactivar después de instalar.
    'installer_enabled' => (bool) env('INSTALLER_ENABLED', false),

    'brand' => [
        // Nombre comercial de la plataforma (independiente de APP_NAME para no depender del .env del servidor).
        'name' => env('BRAND_NAME', 'ID-Hub'),
        'tagline' => 'Tu WISP bajo control',
        // Enlace "Agenda una demo" del login (WhatsApp, Calendly, formulario...). Vacío = no se muestra.
        'sales_url' => env('WISP_SALES_URL'),
        // Destino de las solicitudes de demo del formulario de la página pública.
        'sales_email' => env('WISP_SALES_EMAIL', 'dan@impactosdigitales.com'),
    ],

    // Agenda de demos del CRM de ventas (Panel SaaS → Ventas). Horario en la zona de config('app.timezone').
    'demos' => [
        'self_booking' => env('WISP_DEMO_SELF_BOOKING', true),  // el prospecto elige horario desde la página pública
        'duration' => 45,                                       // minutos
        'weekdays' => [1, 2, 3, 4, 5],                          // 1 = lunes … 7 = domingo
        'hours' => ['10:00', '11:00', '12:00', '13:00', '16:00', '17:00'],
        'horizon_days' => 14,                                   // cuántos días hacia adelante se ofrecen
        'min_notice_hours' => 18,                               // anticipación mínima para autoagendar
        'meeting_url' => env('WISP_DEMO_MEETING_URL'),          // enlace fijo de videollamada (Meet/Zoom), opcional
        'trial_days' => 14,                                     // prueba gratuita al convertir un prospecto en WISP
    ],

    // Dirección a partir del GPS (botón "Usar mi ubicación"): Nominatim de OpenStreetMap.
    'geocoding' => [
        'url' => env('GEOCODING_URL', 'https://nominatim.openstreetmap.org'),
        'email' => env('GEOCODING_EMAIL', env('WISP_SALES_EMAIL', 'dan@impactosdigitales.com')),
    ],

    'roles' => [
        'superadmin' => 'Superadministrador',
        'admin' => 'Administrador WISP',
        'finanzas' => 'Finanzas',
        'soporte' => 'Soporte',
        'tecnico' => 'Técnico',
        'cobrador' => 'Cobrador',
        'supervisor' => 'Supervisor',
    ],

    'client_status' => [
        'prospecto' => ['Prospecto', 'slate'],
        'instalacion_pendiente' => ['Instalación pendiente', 'info'],
        'activo' => ['Activo', 'success'],
        'suspendido' => ['Suspendido', 'danger'],
        'moroso' => ['Moroso', 'warning'],
        'cancelado' => ['Cancelado', 'slate'],
        'en_revision' => ['En revisión', 'purple'],
    ],

    'risk_levels' => [
        'bajo' => ['Bajo', 'success'],
        'medio' => ['Medio', 'warning'],
        'alto' => ['Alto', 'danger'],
    ],

    'periodicity' => [
        'monthly' => ['Mensual', 1],
        'bimonthly' => ['Bimestral', 2],
        'quarterly' => ['Trimestral', 3],
        'semiannual' => ['Semestral', 6],
        'annual' => ['Anual', 12],
    ],

    'charge_types' => [
        'mensualidad' => 'Mensualidad',
        'instalacion' => 'Instalación',
        'recargo' => 'Recargo',
        'reconexion' => 'Reconexión',
        'equipo' => 'Equipo',
        'otro' => 'Otro',
    ],

    'charge_status' => [
        'pending' => ['Pendiente', 'warning'],
        'partial' => ['Parcial', 'info'],
        'paid' => ['Pagado', 'success'],
        'cancelled' => ['Cancelado', 'slate'],
    ],

    'payment_methods' => [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'deposito' => 'Depósito',
        'tarjeta' => 'Tarjeta (terminal)',
        'mercadopago' => 'Mercado Pago',
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'oxxo' => 'OXXO Pay',
        'saldo' => 'Saldo a favor',
        'descuento' => 'Descuento',
        'bonificacion' => 'Bonificación',
        'referido' => 'Recompensa referido',
    ],

    'payment_status' => [
        'pending' => ['Pendiente', 'warning'],
        'completed' => ['Aplicado', 'success'],
        'failed' => ['Fallido', 'danger'],
        'refunded' => ['Reembolsado', 'slate'],
        'cancelled' => ['Cancelado', 'slate'],
    ],

    'gateways' => [
        'mercadopago' => 'Mercado Pago',
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'oxxo' => 'OXXO Pay (vía Stripe)',
    ],

    'visit_results' => [
        'pago' => ['Pago', 'success'],
        'no_localizado' => ['No localizado', 'slate'],
        'promesa' => ['Promesa de pago', 'info'],
        'rechazo' => ['Rechazo', 'danger'],
        'cerrado' => ['Domicilio cerrado', 'warning'],
    ],

    'device_types' => [
        'mikrotik' => 'MikroTik',
        'antenna' => 'Antena',
        'cpe' => 'CPE',
        'ap' => 'Access Point',
        'router' => 'Router',
        'switch' => 'Switch',
        'ups' => 'UPS',
        'power' => 'Fuente',
        'other' => 'Otro',
    ],

    'device_status' => [
        'online' => ['Operativo', 'success'],
        'warning' => ['Advertencia', 'warning'],
        'offline' => ['Fallo', 'danger'],
        'unknown' => ['Sin comunicación', 'slate'],
        'maintenance' => ['Mantenimiento', 'info'],
        'retired' => ['Retirado', 'slate'],
        'in_stock' => ['En almacén', 'info'],
    ],

    'service_methods' => [
        'pppoe' => 'PPPoE',
        'hotspot' => 'Hotspot',
        'dhcp' => 'DHCP / IP estática',
        'queue' => 'Simple Queue',
    ],

    'api_modes' => [
        'api' => 'API RouterOS (8728/8729)',
        'rest' => 'REST API (RouterOS v7)',
        'push' => 'Agente push (script en MikroTik)',
        'simulated' => 'Simulador (demo)',
    ],

    'prospect_status' => [
        'nuevo' => ['Nuevo', 'info'],
        'contactado' => ['Contactado', 'purple'],
        'viable' => ['Viable', 'success'],
        'sin_cobertura' => ['Sin cobertura', 'danger'],
        'instalacion_pendiente' => ['Instalación pendiente', 'warning'],
        'convertido' => ['Convertido', 'teal'],
        'descartado' => ['Descartado', 'slate'],
    ],

    'prospect_sources' => [
        'facebook' => 'Facebook',
        'whatsapp' => 'WhatsApp',
        'referido' => 'Referido',
        'volante' => 'Volante / lona',
        'web' => 'Sitio web',
        'portal' => 'Portal cautivo',
        'llamada' => 'Llamada',
        'otro' => 'Otro',
    ],

    'installation_status' => [
        'cotizacion' => ['Cotización', 'slate'],
        'aceptada' => ['Aceptada', 'info'],
        'programada' => ['Programada', 'purple'],
        'en_proceso' => ['En proceso', 'warning'],
        'instalada' => ['Instalada', 'teal'],
        'activada' => ['Activada', 'success'],
        'cancelada' => ['Cancelada', 'danger'],
    ],

    'installation_checklist' => [
        'antena' => 'Antena instalada',
        'cableado' => 'Cableado',
        'poe' => 'PoE',
        'router' => 'Router',
        'configuracion' => 'Configuración',
        'velocidad' => 'Prueba de velocidad',
        'senal' => 'Prueba de señal',
        'fotografia' => 'Fotografía',
        'firma' => 'Firma',
    ],

    'ticket_categories' => [
        'sin_internet' => 'Sin Internet',
        'lento' => 'Internet lento',
        'antena' => 'Problema con antena',
        'router' => 'Problema con router',
        'pago' => 'Pago no reflejado',
        'instalacion' => 'Solicitud de instalación',
        'domicilio' => 'Cambio de domicilio',
        'plan' => 'Cambio de plan',
        'infraestructura' => 'Falla de infraestructura',
        'otro' => 'Otro',
    ],

    'ticket_priorities' => [
        'critica' => ['Crítica', 'danger', 120],
        'alta' => ['Alta', 'warning', 240],
        'media' => ['Media', 'info', 480],
        'baja' => ['Baja', 'success', 1440],
    ],

    'ticket_status' => [
        'abierto' => ['Abierto', 'info'],
        'asignado' => ['Asignado', 'purple'],
        'en_proceso' => ['En proceso', 'warning'],
        'en_espera' => ['En espera', 'slate'],
        'resuelto' => ['Resuelto', 'success'],
        'cerrado' => ['Cerrado', 'teal'],
        'cancelado' => ['Cancelado', 'slate'],
    ],

    'evidence_kinds' => [
        'antes' => 'Foto antes',
        'durante' => 'Foto durante',
        'despues' => 'Foto después',
        'equipo' => 'Foto del equipo',
        'instalacion' => 'Foto de instalación',
        'firma' => 'Firma',
        'comprobante' => 'Comprobante',
        'otro' => 'Otro',
    ],

    'inventory_categories' => [
        'antena' => 'Antenas',
        'cpe' => 'CPE',
        'router' => 'Routers',
        'cable' => 'Cable',
        'conector' => 'Conectores',
        'fuente' => 'Fuentes',
        'poe' => 'PoE',
        'torre' => 'Torres',
        'herraje' => 'Herrajes',
        'refaccion' => 'Refacciones',
    ],

    'inventory_movement_types' => [
        'entrada' => ['Entrada', 'success'],
        'salida' => ['Salida', 'warning'],
        'instalacion' => ['Instalación', 'info'],
        'devolucion' => ['Devolución', 'purple'],
        'ajuste' => ['Ajuste', 'slate'],
    ],

    'alert_types' => [
        'repeater_saturated' => 'Repetidor saturado',
        'repeater_down' => 'Repetidor caído',
        'mass_disconnect' => 'Muchos clientes desconectados',
        'abnormal_signal' => 'Cliente con señal anormal',
        'recurrent_delay' => 'Cliente con retrasos recurrentes',
        'sla_risk' => 'Ticket próximo a vencer',
        'sla_breached' => 'Ticket con SLA vencido',
        'low_stock' => 'Inventario bajo',
        'device_offline' => 'Equipo sin comunicación',
        'churn_spike' => 'Aumento anormal de cancelaciones',
        'zone_growth' => 'Zona con crecimiento acelerado',
        'anomaly_mac' => 'MAC no registrada',
        'anomaly_unauthorized' => 'Dispositivo no autorizado',
        'anomaly_multi_device' => 'Múltiples dispositivos',
        'anomaly_cpe_change' => 'Cambio de CPE',
        'anomaly_duplicate_ip' => 'IP duplicada',
        'anomaly_usage' => 'Consumo anormal',
        'anomaly_flapping' => 'Desconexiones frecuentes',
        'high_latency' => 'Latencia elevada',
        'packet_loss' => 'Pérdida de paquetes',
    ],

    'alert_severity' => [
        'info' => ['Info', 'info'],
        'warning' => ['Advertencia', 'warning'],
        'critical' => ['Crítica', 'danger'],
    ],

    'channels' => [
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'email' => 'Correo',
        'push' => 'Push',
    ],

    'message_events' => [
        'reminder' => 'Recordatorio previo al vencimiento',
        'due' => 'Aviso de vencimiento',
        'pending' => 'Aviso de pago pendiente',
        'suspension_warning' => 'Aviso de suspensión',
        'suspended' => 'Suspensión ejecutada',
        'payment_received' => 'Pago recibido',
        'reconnected' => 'Reconexión',
        'ticket_created' => 'Ticket creado',
        'ticket_assigned' => 'Ticket asignado',
        'ticket_updated' => 'Ticket actualizado',
        'technician_on_way' => 'Técnico en camino',
        'maintenance' => 'Mantenimiento programado',
        'outage' => 'Incidencia general',
        'promotion' => 'Promoción',
        'welcome' => 'Bienvenida',
        'payment_link' => 'Enlace de pago',
    ],

    'campaign_segments' => [
        'all' => 'Todos los clientes',
        'zone' => 'Una zona',
        'repeater' => 'Un repetidor',
        'plan' => 'Un plan',
        'morosos' => 'Clientes morosos',
        'activos' => 'Clientes activos',
        'nuevos' => 'Clientes nuevos (30 días)',
        'suspendidos' => 'Clientes suspendidos',
    ],

    'automation_events' => [
        'payment_overdue' => 'Pago vencido (cliente supera tolerancia)',
        'payment_due_soon' => 'Pago próximo a vencer',
        'payment_received' => 'Pago recibido',
        'client_created' => 'Cliente registrado',
        'client_suspended' => 'Cliente suspendido',
        'repeater_down' => 'Repetidor desconectado',
        'repeater_saturated' => 'Repetidor saturado',
        'device_offline' => 'Equipo sin comunicación',
        'ticket_created' => 'Ticket creado',
        'ticket_sla_risk' => 'Ticket próximo a vencer SLA',
        'low_stock' => 'Inventario bajo',
        'high_risk_client' => 'Cliente con riesgo alto de morosidad',
    ],

    'automation_actions' => [
        'suspend_service' => 'Suspender servicio en MikroTik',
        'reconnect_service' => 'Reactivar servicio en MikroTik',
        'send_message' => 'Enviar mensaje al cliente',
        'notify_staff' => 'Notificar a personal',
        'create_ticket' => 'Crear ticket automático',
        'assign_on_call' => 'Asignar técnico de guardia',
        'create_alert' => 'Generar alerta',
        'add_late_fee' => 'Aplicar recargo',
        'set_status' => 'Cambiar estado del cliente',
    ],

    'promotion_types' => [
        'descuento_pct' => 'Descuento %',
        'descuento_monto' => 'Descuento $',
        'mes_gratis' => 'Mes gratis',
        'instalacion_gratis' => 'Instalación gratis',
    ],

    'referral_status' => [
        'pendiente' => ['Pendiente', 'warning'],
        'validado' => ['Validado', 'info'],
        'recompensado' => ['Recompensado', 'success'],
        'cancelado' => ['Cancelado', 'slate'],
    ],

    'reward_types' => [
        'mes_gratis' => '1 mes gratis',
        'descuento' => 'Descuento',
        'saldo' => 'Saldo a favor',
        'promocion' => 'Promoción personalizada',
    ],

    'ad_business_types' => [
        'comercio' => 'Comercio',
        'restaurante' => 'Restaurante',
        'farmacia' => 'Farmacia',
        'escuela' => 'Escuela',
        'servicio' => 'Servicio local',
    ],

    // Rutas de campo (módulo de operación)
    'route_status' => [
        'planned' => ['Planeada', 'info'],
        'in_progress' => ['En curso', 'warning'],
        'completed' => ['Completada', 'success'],
    ],

    'route_stop_status' => [
        'pending' => ['Pendiente', 'slate'],
        'arrived' => ['En sitio', 'warning'],
        'done' => ['Atendida', 'success'],
        'skipped' => ['Omitida', 'danger'],
    ],

    /*
    | Configuración por defecto de cada WISP. Se accede con $tenant->setting('billing.grace_days').
    */
    'defaults' => [
        'billing' => [
            'mode' => 'fixed_day',          // fixed_day | anniversary
            'billing_day' => 1,             // día de corte (generación de cargos)
            'due_days' => 5,                // días entre emisión y fecha límite
            'grace_days' => 3,              // días de tolerancia tras fecha límite
            'cut_hour' => '10:00',          // hora de ejecución de cortes
            'reminder_days_before' => 3,
            'late_fee' => 50,
            'reconnection_fee' => 0,
            'block_type' => 'total',        // partial | total
            'auto_suspend' => true,
            'auto_reconnect' => true,
            'reconnect_requires_full_payment' => true,
            'folio_prefix' => 'REC',
            'reminder_hour' => '09:00',     // hora (zona del WISP) a partir de la cual se envían recordatorios/avisos
            'payment_link_days' => 10,      // vigencia de enlaces "Pagar ahora"
        ],
        'messaging' => [
            'whatsapp_provider' => 'simulated', // simulated | meta | twilio
            'whatsapp_token' => null,
            'whatsapp_phone_id' => null,
            'sms_provider' => 'simulated',      // simulated | twilio
            'twilio_sid' => null,
            'twilio_token' => null,
            'twilio_from' => null,
            'twilio_whatsapp_from' => null,
            'email_enabled' => true,
            'push_provider' => 'simulated',     // simulated | fcm
            'fcm_server_key' => null,
            'default_channels' => ['whatsapp'],
        ],
        'network' => [
            'signal_min_dbm' => -75,
            'latency_max_ms' => 80,
            'packet_loss_max' => 5,
            'mass_disconnect_threshold' => 15,
            'usage_anomaly_factor' => 3,
        ],
        'captive' => [
            'title' => 'Bienvenido',
            'welcome' => 'Conéctate a Internet rápido y seguro.',
            'terms' => 'Al conectarte aceptas el uso responsable del servicio.',
            'methods' => ['usuario', 'codigo', 'voucher'],
            'redirect_url' => null,
            'background' => null,
            'show_ads' => true,
            'show_promotions' => true,
        ],
        'referrals' => [
            'enabled' => true,
            'reward_type' => 'mes_gratis',
            'reward_value' => 0,
            'code_prefix' => 'INVITA',
        ],
        'contract' => [
            'template' => "CONTRATO DE PRESTACIÓN DE SERVICIO DE INTERNET\n\nEntre {empresa} (\"EL PROVEEDOR\") y {cliente} (\"EL CLIENTE\"), con domicilio en {direccion}, se celebra el presente contrato para la prestación del servicio de Internet inalámbrico bajo el plan {plan} ({velocidad}) con una cuota {periodicidad} de {precio}.\n\n1. El pago deberá realizarse a más tardar en la fecha límite indicada en el estado de cuenta.\n2. Transcurridos {tolerancia} días de tolerancia sin pago, el servicio podrá ser suspendido.\n3. El equipo instalado es propiedad de EL PROVEEDOR salvo que se indique lo contrario.\n4. EL CLIENTE se compromete a hacer uso responsable del servicio.\n\nFecha: {fecha}",
        ],
        'commissions' => [
            'collector_percent' => 0,
            'technician_install_fee' => 0,
        ],
        // Parámetros del análisis de expansión (BI, sección 47)
        'bi' => [
            'repeater_cost_estimate' => 45000,   // inversión estimada de un repetidor nuevo
            'sector_cost_estimate' => 12000,     // ampliar un repetidor existente (antena/sector)
            'expected_conversion' => 60,         // % de prospectos que se espera convertir
            'cluster_km' => 1,                   // tamaño de la cuadrícula de agrupación de demanda
        ],
    ],

    /*
    | Catálogos del módulo de administración / SaaS
    */
    'tenant_status' => [
        'trial' => ['Prueba', 'info'],
        'active' => ['Activo', 'success'],
        'suspended' => ['Suspendido', 'danger'],
        'cancelled' => ['Cancelado', 'slate'],
    ],

    'saas_invoice_status' => [
        'pending' => ['Pendiente', 'warning'],
        'paid' => ['Pagada', 'success'],
        'overdue' => ['Vencida', 'danger'],
        'cancelled' => ['Cancelada', 'slate'],
    ],

    'currencies' => [
        'MXN' => 'Peso mexicano (MXN)',
        'USD' => 'Dólar estadounidense (USD)',
        'GTQ' => 'Quetzal (GTQ)',
        'HNL' => 'Lempira (HNL)',
        'COP' => 'Peso colombiano (COP)',
        'PEN' => 'Sol peruano (PEN)',
    ],

    'timezones' => [
        'America/Mexico_City' => 'Centro de México (CDMX)',
        'America/Monterrey' => 'Monterrey',
        'America/Merida' => 'Mérida',
        'America/Cancun' => 'Cancún (Sureste)',
        'America/Chihuahua' => 'Chihuahua',
        'America/Mazatlan' => 'Pacífico (Mazatlán)',
        'America/Hermosillo' => 'Sonora (Hermosillo)',
        'America/Tijuana' => 'Noroeste (Tijuana)',
        'America/Guatemala' => 'Guatemala',
        'America/Bogota' => 'Bogotá',
        'America/Lima' => 'Lima',
    ],

    'tax_regimes' => [
        '601' => '601 · General de Ley Personas Morales',
        '603' => '603 · Personas Morales con Fines no Lucrativos',
        '612' => '612 · Personas Físicas con Actividades Empresariales y Profesionales',
        '621' => '621 · Incorporación Fiscal',
        '626' => '626 · Régimen Simplificado de Confianza',
    ],

    'audit_actions' => [
        'created' => ['Creación', 'success'],
        'updated' => ['Modificación', 'info'],
        'deleted' => ['Eliminación', 'danger'],
        'login' => ['Inicio de sesión', 'slate'],
        'logout' => ['Cierre de sesión', 'slate'],
        'suspend' => ['Suspensión', 'danger'],
        'reconnect' => ['Reconexión', 'success'],
        'cancel' => ['Cancelación', 'danger'],
        'payment' => ['Pago', 'success'],
        'cancel_payment' => ['Cancelación de pago', 'warning'],
        'cancel_charge' => ['Cancelación de cargo', 'warning'],
        'document' => ['Documento', 'slate'],
        'settings' => ['Configuración', 'purple'],
        'password_reset' => ['Restablecer contraseña', 'warning'],
        'activate' => ['Activación', 'success'],
        'deactivate' => ['Desactivación', 'warning'],
        'export' => ['Exportación', 'slate'],
        'tenant_suspend' => ['WISP suspendido', 'danger'],
        'tenant_reactivate' => ['WISP reactivado', 'success'],
        'invoice' => ['Factura SaaS', 'purple'],
    ],

    /*
    | Módulo Crecimiento (prospectos, demanda, portal cautivo).
    */
    'growth' => [
        'potential_coverage_km' => 4,   // distancia al repetidor más cercano que se considera "cobertura potencial"
        'cluster_km' => 1,              // tamaño de celda para concentración de demanda
        'hotspot_min_prospects' => 5,   // mínimo de interesados para "alta demanda"
        'hotspot_max_coverage_pct' => 50, // % máximo con cobertura para considerarse "baja cobertura"
        'captive_login_methods' => [
            'usuario' => 'Usuario y contraseña',
            'codigo' => 'Código de cliente',
            'voucher' => 'Voucher / ficha',
        ],
        'captive_session_methods' => [
            'usuario' => ['Usuario', 'brand'],
            'codigo' => ['Código', 'purple'],
            'voucher' => ['Voucher', 'teal'],
        ],
        'voucher_status' => [
            'available' => ['Disponible', 'success'],
            'used' => ['Usado', 'info'],
            'expired' => ['Expirado', 'slate'],
        ],
    ],
    /*
    | Cobranza / pagos en línea (módulo Billing).
    | gateway_credentials: llaves que la pantalla de configuración debe capturar en
    | PaymentGateway::credentials (arreglo cifrado) por proveedor. Las marcadas como
    | "required" son necesarias para operar en modo real; sin ellas y con sandbox
    | activo se usa el checkout simulado.
    */
    'gateway_credentials' => [
        'mercadopago' => [
            'access_token' => ['Access token (APP_USR-... / TEST-...)', 'required'],
            'public_key' => ['Public key', 'optional'],
            'webhook_secret' => ['Clave secreta de webhooks (firma x-signature)', 'optional'],
        ],
        'stripe' => [
            'secret_key' => ['Secret key (sk_live_... / sk_test_...)', 'required'],
            'publishable_key' => ['Publishable key (pk_...)', 'optional'],
            'webhook_secret' => ['Signing secret del webhook (whsec_...)', 'required'],
        ],
        'oxxo' => [
            'secret_key' => ['Secret key de Stripe (vacío = usa la de Stripe)', 'optional'],
            'webhook_secret' => ['Signing secret (vacío = usa el de Stripe)', 'optional'],
        ],
        'paypal' => [
            'client_id' => ['Client ID', 'required'],
            'client_secret' => ['Client secret', 'required'],
            'webhook_id' => ['Webhook ID (opcional)', 'optional'],
        ],
    ],

    'payment_link_status' => [
        'pending' => ['Pendiente', 'warning'],
        'paid' => ['Pagado', 'success'],
        'expired' => ['Expirado', 'slate'],
        'cancelled' => ['Cancelado', 'slate'],
    ],

    'promise_status' => [
        'vigente' => ['Vigente', 'info'],
        'cumplida' => ['Cumplida', 'success'],
        'incumplida' => ['Incumplida', 'danger'],
    ],

    'cash_cut_status' => [
        'open' => ['Abierto', 'info'],
        'closed' => ['Cerrado · por entregar', 'purple'],
        'delivered' => ['Entregado', 'warning'],
        'reconciled' => ['Conciliado', 'success'],
    ],
];
