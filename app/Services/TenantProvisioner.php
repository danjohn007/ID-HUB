<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\NotificationTemplate;
use App\Models\PaymentGateway;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Services\Messaging\DefaultTemplates;
use App\Support\TenantContext;

/**
 * Configuración inicial de un WISP nuevo: plantillas de mensajes, SLA,
 * pasarelas (deshabilitadas) y reglas de automatización del sistema.
 */
class TenantProvisioner
{
    public function provision(Tenant $tenant): void
    {
        app(TenantContext::class)->run($tenant, function () {
            foreach (DefaultTemplates::BODIES as $event => $body) {
                NotificationTemplate::firstOrCreate(
                    ['event' => $event, 'channel' => 'whatsapp'],
                    ['body' => $body, 'subject' => DefaultTemplates::subject($event), 'active' => true]
                );
            }
            foreach (['reminder', 'suspended', 'payment_received', 'maintenance'] as $event) {
                NotificationTemplate::firstOrCreate(
                    ['event' => $event, 'channel' => 'email'],
                    ['body' => DefaultTemplates::body($event), 'subject' => DefaultTemplates::subject($event), 'active' => true]
                );
            }

            foreach (config('wisp.ticket_priorities') as $priority => [$label, $color, $minutes]) {
                SlaPolicy::firstOrCreate(['priority' => $priority], ['resolution_minutes' => $minutes, 'warn_percent' => 75]);
            }

            foreach (['mercadopago' => [3.49, 4], 'stripe' => [3.6, 3], 'paypal' => [3.95, 4], 'oxxo' => [3.6, 3]] as $provider => [$pct, $fixed]) {
                PaymentGateway::firstOrCreate(['provider' => $provider], ['enabled' => false, 'sandbox' => true, 'fee_percent' => $pct, 'fee_fixed' => $fixed]);
            }

            foreach ($this->systemRules() as $rule) {
                AutomationRule::firstOrCreate(['name' => $rule['name']], $rule + ['system' => true, 'active' => true]);
            }
        });
    }

    public function systemRules(): array
    {
        return [
            [
                'name' => 'Corte automático por falta de pago',
                'description' => 'Cuando el cliente supera los días de tolerancia: suspender en MikroTik, aplicar recargo y avisar por WhatsApp.',
                'event' => 'payment_overdue',
                'conditions' => [['field' => 'service_status', 'operator' => 'in', 'value' => 'activo,moroso']],
                'actions' => [
                    ['type' => 'suspend_service', 'params' => ['notify' => false]],
                    ['type' => 'add_late_fee', 'params' => []],
                    ['type' => 'send_message', 'params' => ['event' => 'suspended']],
                ],
            ],
            [
                'name' => 'Reconexión automática al recibir pago',
                'description' => 'Si el cliente está suspendido y liquida su saldo, se reactiva en MikroTik y se le notifica.',
                'event' => 'payment_received',
                'conditions' => [['field' => 'service_status', 'operator' => '=', 'value' => 'suspendido']],
                'actions' => [
                    ['type' => 'reconnect_service', 'params' => ['require_zero_balance' => true, 'notify' => true]],
                ],
            ],
            [
                'name' => 'Recordatorio previo al vencimiento',
                'description' => 'Envía recordatorio de pago días antes de la fecha límite.',
                'event' => 'payment_due_soon',
                'conditions' => [],
                'actions' => [['type' => 'send_message', 'params' => ['event' => 'reminder']]],
            ],
            [
                'name' => 'Repetidor caído: ticket crítico y técnico de guardia',
                'description' => 'Al detectar un repetidor desconectado: alerta crítica, ticket automático, asignación al técnico de guardia y aviso al personal.',
                'event' => 'repeater_down',
                'conditions' => [['field' => 'affected_clients', 'operator' => '>=', 'value' => '1']],
                'actions' => [
                    ['type' => 'create_ticket', 'params' => ['category' => 'infraestructura', 'priority' => 'critica', 'subject' => 'Repetidor {repetidor} fuera de servicio', 'description' => '{afectados} clientes afectados. Ticket generado automáticamente.']],
                    ['type' => 'assign_on_call', 'params' => []],
                    ['type' => 'notify_staff', 'params' => ['roles' => ['admin', 'supervisor', 'soporte'], 'message' => 'ALERTA: Repetidor {repetidor} fuera de servicio. {afectados} clientes afectados.']],
                ],
            ],
            [
                'name' => 'Ticket próximo a vencer SLA',
                'description' => 'Avisa a supervisores cuando un ticket se acerca al vencimiento de su SLA.',
                'event' => 'ticket_sla_risk',
                'conditions' => [],
                'actions' => [['type' => 'notify_staff', 'params' => ['roles' => ['supervisor'], 'message' => 'El ticket {folio} está próximo a vencer su SLA.']]],
            ],
            [
                'name' => 'Repetidor saturado',
                'description' => 'Notifica cuando un repetidor supera su umbral de capacidad.',
                'event' => 'repeater_saturated',
                'conditions' => [],
                'actions' => [['type' => 'notify_staff', 'params' => ['roles' => ['admin', 'supervisor'], 'message' => 'El repetidor {repetidor} superó su umbral de capacidad.']]],
            ],
        ];
    }
}
