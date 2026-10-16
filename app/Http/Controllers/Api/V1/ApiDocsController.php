<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

/**
 * Página pública de documentación de la API (/api/docs).
 * El detalle completo con ejemplos está en docs/API.md.
 */
class ApiDocsController extends Controller
{
    public function __invoke()
    {
        return view('public.api-docs', [
            'base' => url('/api/v1'),
            'groups' => self::groups(),
        ]);
    }

    /** [método, ruta, descripción, permiso / requisito] agrupados por sección. */
    public static function groups(): array
    {
        return [
            [
                'title' => 'Autenticación',
                'auth' => 'Pública (límite: 10 intentos/min)',
                'intro' => 'Obtén un token Sanctum y envíalo como Authorization: Bearer <token>. Los tokens del personal llevan las habilidades de su rol (config/permissions.php).',
                'endpoints' => [
                    ['POST', '/auth/login', 'Login del personal: email, password, device_name → token, usuario y WISP.', null],
                    ['POST', '/client-auth/login', 'Login del cliente: identifier (ID de cliente o usuario PPPoE), password (portal o PPPoE), device_name, tenant (slug, opcional).', null],
                    ['POST', '/auth/logout', 'Revoca el token actual (staff o cliente). push_token opcional para darlo de baja.', 'token'],
                    ['GET', '/me', 'Usuario o cliente del token, WISP y permisos.', 'token'],
                ],
            ],
            [
                'title' => 'Clientes y planes',
                'auth' => 'Staff · plan con "api" o "app_tecnico"',
                'endpoints' => [
                    ['GET', '/clients', 'Listado paginado. Filtros: search, status, zone_id, plan_id, repeater_id, collector_id, technician_id, with_balance, online, updated_since, sort, per_page.', 'clients.view'],
                    ['GET', '/clients/{id}', 'Detalle del cliente con plan, zona, repetidor, técnico y cobrador.', 'clients.view'],
                    ['GET', '/clients/{id}/statement', 'Estado de cuenta: saldos, cargos, pagos (12 meses) y pdf_url firmado.', 'clients.view'],
                    ['POST', '/clients', 'Alta de cliente (función "api"). activate, charge_installation, referral_code opcionales.', 'clients.manage'],
                    ['PATCH', '/clients/{id}', 'Edición parcial; el cambio de plan se aplica con ServiceControl (función "api").', 'clients.manage'],
                    ['GET', '/plans', 'Planes activos (all=1 para todos, public=1).', 'clients.view'],
                    ['GET', '/plans/{id}', 'Detalle de un plan.', 'clients.view'],
                ],
            ],
            [
                'title' => 'Cobranza',
                'auth' => 'Staff · plan con "api"',
                'endpoints' => [
                    ['GET', '/payments', 'Pagos. Filtros: client_id, method, status, collector_id, folio, from, to.', 'billing.view'],
                    ['POST', '/payments', 'Registra un pago (BillingService::registerPayment, aplicación FIFO): client_id, amount, method, reference.', 'billing.manage'],
                    ['GET', '/charges', 'Cargos. Filtros: client_id, status, type, overdue, from, to.', 'billing.view'],
                ],
            ],
            [
                'title' => 'Tickets',
                'auth' => 'Staff · plan con "api" o "app_tecnico"',
                'endpoints' => [
                    ['GET', '/tickets', 'Listado. Filtros: status (o "open"), priority, category, client_id, technician_id, mine=1, search.', 'tickets.view'],
                    ['GET', '/tickets/{id}', 'Detalle con comentarios y evidencias.', 'tickets.view'],
                    ['POST', '/tickets', 'Crear ticket (TicketService, SLA automático).', 'tickets.manage'],
                    ['PATCH', '/tickets/{id}/status', 'Cambiar estado: status, note, lat, lng. Técnicos: sólo tickets asignados.', 'tickets.manage | field.work'],
                    ['POST', '/tickets/{id}/assign', 'Asignar técnico: technician_id, notify.', 'tickets.manage'],
                    ['GET', '/tickets/{id}/comments', 'Historial de comentarios.', 'tickets.view'],
                    ['POST', '/tickets/{id}/comments', 'Agregar comentario: body, internal.', 'tickets.manage | field.work'],
                    ['POST', '/tickets/{id}/attachments', 'Evidencia (multipart): file, kind (antes, durante, despues, equipo, instalacion, firma…), lat, lng, taken_at.', 'tickets.manage | field.work'],
                    ['POST', '/tickets/{id}/materials', 'Material usado (se descuenta del inventario): item_id, quantity, type (instalacion|salida), serial.', 'tickets.manage | field.work'],
                    ['DELETE', '/tickets/{id}/materials/{movimiento}', 'Devuelve al inventario un material registrado por error.', 'tickets.manage | field.work'],
                    ['POST', '/tickets/{id}/signature', 'Firma de conformidad: signed_by + signature (PNG multipart) o signature_data (data URL).', 'tickets.manage | field.work'],
                ],
            ],
            [
                'title' => 'App del técnico (campo)',
                'auth' => 'Staff · plan con "api" o "app_tecnico"',
                'endpoints' => [
                    ['GET', '/field/today', 'Resumen del día, ruta con paradas ordenadas, navegación, tickets abiertos e instalaciones asignadas (date, type).', 'field.work | collection.field'],
                    ['GET', '/inventory', 'Existencias por categoría (category, search, in_stock).', 'inventory.view | field.work'],
                    ['GET', '/installations', 'Órdenes de instalación asignadas (status=open por defecto).', 'installations.manage | field.work'],
                    ['GET', '/installations/{id}', 'Detalle: contacto, plan, checklist, mediciones, fotos, materiales y firma.', 'installations.manage | field.work'],
                    ['POST', '/installations/{id}/start', 'Iniciar instalación (en_proceso).', 'installations.manage | field.work'],
                    ['PATCH', '/installations/{id}/checklist', 'Marcar punto del checklist: key, done.', 'installations.manage | field.work'],
                    ['PATCH', '/installations/{id}/measures', 'Prueba de señal y velocidad: signal_dbm, speed_down, speed_up.', 'installations.manage | field.work'],
                    ['POST', '/installations/{id}/attachments', 'Fotografía (multipart): file, kind, lat, lng.', 'installations.manage | field.work'],
                    ['POST', '/installations/{id}/materials', 'Material usado (igual que en tickets).', 'installations.manage | field.work'],
                    ['POST', '/installations/{id}/signature', 'Firma del cliente (igual que en tickets).', 'installations.manage | field.work'],
                    ['POST', '/installations/{id}/complete', 'Finalizar (instalada); requiere checklist completo.', 'installations.manage | field.work'],
                    ['POST', '/field/stops/{id}/arrive', 'Registrar llegada a la parada (lat, lng).', 'field.work | collection.field'],
                    ['POST', '/field/stops/{id}/depart', 'Registrar salida de la parada (lat, lng).', 'field.work | collection.field'],
                    ['POST', '/field/tickets/{id}/on-way', 'En camino: notifica al cliente "Técnico en camino".', 'field.work'],
                    ['POST', '/field/tickets/{id}/arrive', 'Llegada directa al ticket.', 'field.work'],
                    ['POST', '/field/tickets/{id}/depart', 'Salida directa del ticket.', 'field.work'],
                    ['POST', '/field/location', 'Actualiza la ubicación GPS del usuario (users.last_lat / last_lng).', 'field.work | collection.field'],
                    ['POST', '/push-tokens', 'Registrar token push del dispositivo del personal (token, platform).', 'staff'],
                    ['DELETE', '/push-tokens', 'Dar de baja token push.', 'staff'],
                ],
            ],
            [
                'title' => 'App del cobrador',
                'auth' => 'Staff · plan con "api" o "app_tecnico"',
                'endpoints' => [
                    ['GET', '/collection/pending', 'Clientes con saldo asignados al cobrador (all=1 con billing.view), con atraso y última visita.', 'collection.field'],
                    ['GET', '/collection/clients/{id}/visits', 'Historial de visitas de cobranza del cliente.', 'collection.field'],
                    ['POST', '/collection/visits', 'Visita sin pago: result (no_localizado, promesa, rechazo, cerrado), promise_date, promise_amount, notes, evidence, lat, lng.', 'collection.field'],
                    ['POST', '/collection/payments', 'Cobro en efectivo: client_id, amount, evidence, lat, lng → pago + visita + corte de caja abierto.', 'collection.field'],
                    ['GET', '/collection/cash-cut', 'Corte de caja abierto del cobrador y sus cobros.', 'collection.field'],
                ],
            ],
            [
                'title' => 'Red, alertas y métricas',
                'auth' => 'Staff · plan con "api"',
                'endpoints' => [
                    ['GET', '/network/repeaters', 'Repetidores con estado, carga y utilización. Filtros: status, zone_id, search.', 'network.view'],
                    ['GET', '/network/repeaters/{id}', 'Detalle del repetidor y sus equipos.', 'network.view'],
                    ['GET', '/network/devices', 'Equipos con estado y métricas (cpu, latencia, pérdida, señal). Filtros: type, status, repeater_id, client_id, search.', 'network.view'],
                    ['GET', '/network/devices/{id}', 'Detalle de un equipo (sin credenciales).', 'network.view'],
                    ['GET', '/alerts', 'Alertas (status=open|all|resolved, severity, type).', 'alerts.view'],
                    ['GET', '/metrics/summary', 'KPIs del panel principal (MetricsService::headline). Montos sólo con finance.view.', 'dashboard.view'],
                    ['POST', '/integration/key', 'Genera / rota la API key de integraciones del WISP (se muestra una sola vez).', 'settings.manage'],
                ],
            ],
            [
                'title' => 'App del cliente',
                'auth' => 'Token de cliente · plan con "app_cliente" o "api"',
                'endpoints' => [
                    ['GET', '/client/home', 'Inicio: saludo, estado del servicio, plan, próximo pago y fecha ("Hola, Juan · Internet ACTIVO · …").', 'client'],
                    ['GET', '/client/status', 'Estado del servicio, conexión, señal, saldo y atraso.', 'client'],
                    ['GET', '/client/plan', 'Plan contratado y opciones de mejora.', 'client'],
                    ['GET', '/client/usage', 'Consumo del mes (GB) y límite del plan.', 'client'],
                    ['GET', '/client/payments', 'Historial de pagos con receipt_url (PDF firmado, 7 días).', 'client'],
                    ['GET', '/client/charges', 'Cargos pendientes (all=1 para historial).', 'client'],
                    ['POST', '/client/pay', 'Enlace "Pagar ahora" por el saldo o amount.', 'client'],
                    ['POST', '/client/report', 'Reportar falla (crea ticket source=cliente): category, description, subject.', 'client'],
                    ['GET', '/client/tickets', 'Mis reportes (status=open).', 'client'],
                    ['GET', '/client/tickets/{id}', 'Detalle del reporte y comentarios públicos.', 'client'],
                    ['POST', '/client/tickets/{id}/comments', 'Agregar comentario a un reporte abierto.', 'client'],
                    ['GET', '/client/support', 'Datos de contacto de soporte (teléfono, WhatsApp, correo, horario).', 'client'],
                    ['GET', '/client/profile', 'Mis datos.', 'client'],
                    ['PATCH', '/client/profile', 'Actualizar phone, whatsapp, email, references y/o contraseña (current_password, new_password, new_password_confirmation).', 'client'],
                    ['GET', '/client/promotions', 'Promociones vigentes para mi plan.', 'client'],
                    ['GET', '/client/referrals', 'Mi código de referido, enlace para compartir y referidos.', 'client'],
                    ['POST', '/client/push-token', 'Registrar dispositivo para notificaciones push (token, platform).', 'client'],
                    ['DELETE', '/client/push-token', 'Dar de baja el dispositivo.', 'client'],
                ],
            ],
            [
                'title' => 'Integraciones (servidor a servidor)',
                'auth' => 'Encabezado X-Api-Key del WISP · plan con "api"',
                'endpoints' => [
                    ['GET', '/integration/clients', 'Clientes paginados (updated_since, status) para sincronizar CRM / ERP.', 'X-Api-Key'],
                    ['GET', '/integration/clients/{code}', 'Cliente por ID de cliente o usuario PPPoE.', 'X-Api-Key'],
                    ['POST', '/integration/payments', 'Pago externo idempotente: client_code, amount, method, external_id, reference, paid_at.', 'X-Api-Key'],
                ],
            ],
            [
                'title' => 'Documentos',
                'auth' => 'Enlace firmado (sin token)',
                'endpoints' => [
                    ['GET', '/receipts/{payment}?signature=…', 'Comprobante de pago en PDF (URL incluida como receipt_url).', 'firma'],
                    ['GET', '/statements/{client}?signature=…', 'Estado de cuenta en PDF (URL incluida como pdf_url).', 'firma'],
                ],
            ],
        ];
    }
}
