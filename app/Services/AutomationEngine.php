<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\Outage;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\ServiceEvent;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\StaffPush;
use App\Services\MikroTik\MikroTikService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Motor de reglas  Evento → Condición → Acción.
 *
 *   app(AutomationEngine::class)->fire('payment_overdue', $client, ['overdue_days' => 8]);
 *
 * Eventos: config('wisp.automation_events'). Acciones: config('wisp.automation_actions').
 * Condiciones: [{field, operator, value}] evaluadas contra los "hechos" del sujeto (facts()).
 */
class AutomationEngine
{
    public function fire(string $event, Model $subject, array $context = []): array
    {
        $tenant = Tenant::find($subject->tenant_id);
        if (! $tenant) {
            return [];
        }

        return app(TenantContext::class)->run($tenant, function () use ($event, $subject, $context) {
            $rules = AutomationRule::query()->where('event', $event)->where('active', true)->get();
            $results = [];
            foreach ($rules as $rule) {
                $results[] = $this->runRule($rule, $subject, $context);
            }

            return $results;
        });
    }

    public function runRule(AutomationRule $rule, Model $subject, array $context = []): AutomationRun
    {
        $facts = $this->facts($subject, $context);
        $log = [];

        if (! $this->matches($rule->conditions ?? [], $facts)) {
            return $this->record($rule, $subject, 'skipped', ['Condiciones no cumplidas']);
        }

        $status = 'success';
        foreach ($rule->actions ?? [] as $action) {
            try {
                $log[] = $this->execute($action['type'] ?? '', $action['params'] ?? [], $subject, $context, $rule, $facts);
            } catch (\Throwable $e) {
                $status = 'failed';
                $log[] = 'Error en '.($action['type'] ?? '?').': '.$e->getMessage();
            }
        }
        $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->saveQuietly();

        return $this->record($rule, $subject, $status, $log);
    }

    /** Hechos evaluables por las condiciones según el tipo de sujeto. */
    public function facts(Model $subject, array $context = []): array
    {
        $facts = collect($context)->filter(fn ($v) => is_scalar($v) || $v === null)->all();

        if ($subject instanceof Client) {
            $facts += [
                'service_status' => $subject->service_status,
                'balance' => (float) $subject->balance,
                'risk_level' => $subject->risk_level,
                'risk_score' => $subject->risk_score,
                'zone_id' => $subject->zone_id,
                'plan_id' => $subject->plan_id,
                'repeater_id' => $subject->repeater_id,
                'online' => $subject->online,
                'signal_dbm' => $subject->signal_dbm !== null ? (float) $subject->signal_dbm : null,
                'month_usage_gb' => (float) $subject->month_usage_gb,
                // Días de atraso del cargo más antiguo (el evento payment_overdue lo trae en el contexto)
                'overdue_days' => $facts['overdue_days'] ?? app(BillingService::class)->overdueDays($subject),
            ];
        } elseif ($subject instanceof Repeater) {
            $facts += [
                'affected_clients' => $context['affected_clients'] ?? $subject->clientLoad(),
                'utilization' => $subject->utilization(),
                'zone_id' => $subject->zone_id,
                'status' => $subject->status,
            ];
        } elseif ($subject instanceof Ticket) {
            $facts += ['priority' => $subject->priority, 'category' => $subject->category, 'status' => $subject->status];
        } elseif ($subject instanceof Payment) {
            $facts += ['amount' => (float) $subject->amount, 'method' => $subject->method];
        } elseif ($subject instanceof \App\Models\NetworkDevice) {
            $facts += [
                'type' => $subject->type,
                'status' => $subject->status,
                'repeater_id' => $subject->repeater_id,
                'latency_ms' => $subject->latency_ms !== null ? (float) $subject->latency_ms : null,
                'packet_loss' => $subject->packet_loss !== null ? (float) $subject->packet_loss : null,
                'signal_dbm' => $subject->signal_dbm !== null ? (float) $subject->signal_dbm : null,
            ];
        } elseif ($subject instanceof \App\Models\InventoryItem) {
            $facts += ['stock' => (float) $subject->stock, 'min_stock' => (float) $subject->min_stock, 'max_stock' => $subject->max_stock !== null ? (float) $subject->max_stock : null, 'category' => $subject->category];
        }

        return $facts;
    }

    /**
     * Prueba en seco: evalúa las condiciones contra los hechos del sujeto SIN ejecutar acciones
     * ni registrar la ejecución. Devuelve los hechos, el resultado por condición y las acciones
     * que se ejecutarían.
     *
     * @return array{facts: array, conditions: array<int, array{field: ?string, operator: string, value: mixed, actual: mixed, ok: bool}>, matches: bool, actions: array}
     */
    public function dryRun(AutomationRule $rule, Model $subject, array $context = []): array
    {
        $facts = $this->facts($subject, $context);
        $results = [];
        foreach ($rule->conditions ?? [] as $c) {
            $results[] = [
                'field' => $c['field'] ?? null,
                'operator' => $c['operator'] ?? '=',
                'value' => $c['value'] ?? null,
                'actual' => $facts[$c['field'] ?? ''] ?? null,
                'ok' => $this->matches([$c], $facts),
            ];
        }

        return [
            'facts' => $facts,
            'conditions' => $results,
            'matches' => $this->matches($rule->conditions ?? [], $facts),
            'actions' => array_values($rule->actions ?? []),
        ];
    }

    public function matches(array $conditions, array $facts): bool
    {
        foreach ($conditions as $c) {
            $field = $c['field'] ?? null;
            if (! $field) {
                continue;
            }
            $actual = $facts[$field] ?? null;
            $expected = $c['value'] ?? null;
            $ok = match ($c['operator'] ?? '=') {
                '=' => (string) $actual === (string) $expected,
                '!=' => (string) $actual !== (string) $expected,
                '>' => (float) $actual > (float) $expected,
                '>=' => (float) $actual >= (float) $expected,
                '<' => (float) $actual < (float) $expected,
                '<=' => (float) $actual <= (float) $expected,
                'in' => in_array((string) $actual, array_map('trim', explode(',', (string) $expected)), true),
                'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
                default => true,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    protected function execute(string $type, array $params, Model $subject, array &$context, AutomationRule $rule, array $facts): string
    {
        $client = $subject instanceof Client ? $subject : ($subject->client ?? null);
        $reason = 'Automatización: '.$rule->name;

        switch ($type) {
            case 'suspend_service':
                if (! $client) {
                    return 'Sin cliente para suspender';
                }
                if ($client->service_status === 'suspendido') {
                    return 'Cliente ya suspendido';
                }
                app(ServiceControl::class)->suspend($client, $reason, 'automation', $params['notify'] ?? false);

                return "Servicio de {$client->code} suspendido";

            case 'reconnect_service':
                if (! $client || $client->service_status !== 'suspendido') {
                    return 'Cliente no suspendido';
                }
                if ($client->plan && ! $client->plan->auto_reconnect) {
                    return 'El plan '.$client->plan->name.' se reconecta manualmente';
                }
                // "Exigir liquidar el saldo" de Configuración → Cobranza manda sobre el parámetro de la regla.
                $requireZero = (bool) (Tenant::find($client->tenant_id)?->setting('billing.reconnect_requires_full_payment', $params['require_zero_balance'] ?? true));
                if ($requireZero && (float) $client->fresh()->balance > 0.009) {
                    return 'Saldo pendiente: no se reconecta';
                }
                // Días de reconexión: tras N días suspendido la reconexión la decide el personal.
                $maxDays = (int) Tenant::find($client->tenant_id)?->setting('billing.reconnect_max_days', 0);
                if ($maxDays > 0 && $client->suspended_at && $client->suspended_at->lt(now()->subDays($maxDays))) {
                    return "Suspendido hace más de {$maxDays} días: requiere reconexión manual";
                }
                app(ServiceControl::class)->reconnect($client, $reason, 'automation', $params['notify'] ?? false);

                return "Servicio de {$client->code} reactivado";

            case 'send_message':
                if (! $client) {
                    return 'Sin cliente destinatario';
                }
                $vars = array_filter(['monto' => isset($context['amount']) ? money($context['amount']) : null, 'folio' => $context['folio'] ?? null]);
                $sent = app(MessageService::class)->notify($client, $params['event'] ?? 'pending', $vars, $params['channels'] ?? null);

                return 'Mensajes en cola: '.$sent->count();

            case 'notify_staff':
                $roles = $params['roles'] ?? ['admin', 'supervisor'];
                $text = $this->interpolate($params['message'] ?? $rule->name, $subject, $facts);
                $users = User::forTenant($subject->tenant_id)->active()->role($roles)->whereNotNull('phone')->get();
                foreach ($users as $u) {
                    app(MessageService::class)->queue(Tenant::find($subject->tenant_id), 'whatsapp', app(MessageService::class)->phone($u->phone), $text, $rule->name, null, 'staff');
                }
                // También a la app de campo de quien la tenga instalada.
                foreach (User::forTenant($subject->tenant_id)->active()->role($roles)->get() as $u) {
                    app(StaffPush::class)->toUser($u, $rule->name, $text, ['type' => 'alert'], (int) $subject->tenant_id);
                }

                return 'Personal notificado: '.$users->count();

            case 'create_ticket':
                $ticket = app(TicketService::class)->create([
                    'client_id' => $client?->id,
                    'repeater_id' => $subject instanceof Repeater ? $subject->id : ($client?->repeater_id),
                    'outage_id' => ($context['outage'] ?? null) instanceof Outage ? $context['outage']->id : null,
                    'category' => $params['category'] ?? ($subject instanceof Repeater ? 'infraestructura' : 'sin_internet'),
                    'priority' => $params['priority'] ?? 'alta',
                    'subject' => $this->interpolate($params['subject'] ?? $rule->name, $subject, $facts),
                    'description' => $this->interpolate($params['description'] ?? 'Ticket generado automáticamente por la regla "'.$rule->name.'".', $subject, $facts),
                    'source' => 'automatico',
                ]);
                $context['ticket'] = $ticket;
                if (($context['outage'] ?? null) instanceof Outage) {
                    $context['outage']->update(['ticket_id' => $ticket->id]);
                }

                return "Ticket {$ticket->folio} creado";

            case 'assign_on_call':
                $ticket = $context['ticket'] ?? ($subject instanceof Ticket ? $subject : null);
                if (! $ticket) {
                    return 'Sin ticket para asignar';
                }
                $tech = ($subject instanceof Repeater && $subject->technician_id ? User::find($subject->technician_id) : null)
                    ?? User::forTenant($subject->tenant_id)->active()->role('tecnico')->where('on_call', true)->first()
                    ?? User::forTenant($subject->tenant_id)->active()->role('tecnico')->withCount(['tickets as open_tickets' => fn ($q) => $q->open()])->orderBy('open_tickets')->first();
                if (! $tech) {
                    return 'No hay técnico disponible';
                }
                app(TicketService::class)->assign($ticket, $tech);

                return "Asignado a {$tech->name}";

            case 'create_alert':
                app(AlertService::class)->raise(
                    $params['alert_type'] ?? 'automation',
                    $params['severity'] ?? 'warning',
                    $this->interpolate($params['title'] ?? $rule->name, $subject, $facts),
                    $this->interpolate($params['message'] ?? '', $subject, $facts),
                    $subject,
                );

                return 'Alerta generada';

            case 'add_late_fee':
                if (! $client) {
                    return 'Sin cliente';
                }
                $fee = app(BillingService::class)->addLateFee($client);

                return $fee ? 'Recargo aplicado: '.money($fee->total) : 'Recargo ya aplicado este periodo';

            case 'change_plan':
                if (! $client) {
                    return 'Sin cliente';
                }
                $plan = Plan::find($params['plan_id'] ?? null);
                if (! $plan) {
                    throw new \RuntimeException('El plan destino ya no existe');
                }
                if (! empty($params['keep_billing'])) {
                    // Solo velocidad en el equipo (ej. reducir al superar el límite de consumo); el plan facturado no cambia
                    [$applied, $result] = app(ServiceControl::class)->onRouter($client, fn (MikroTikService $mt) => $mt->applyPlan($client, $plan));
                    ServiceEvent::create([
                        'tenant_id' => $client->tenant_id, 'client_id' => $client->id, 'type' => 'speed_change',
                        'reason' => mb_substr("Velocidad de {$plan->name} ({$plan->download_mbps}/{$plan->upload_mbps} Mbps) · {$reason}", 0, 190),
                        'source' => 'automation', 'network_applied' => $applied, 'network_result' => mb_substr($result, 0, 190), 'data' => ['plan_id' => $plan->id],
                    ]);
                    if (! $applied) {
                        throw new \RuntimeException($result);
                    }

                    return "Velocidad de {$plan->name} aplicada a {$client->code}: {$result}";
                }
                if ((int) $client->plan_id === (int) $plan->id) {
                    return "El cliente ya tiene el plan {$plan->name}";
                }
                $event = app(ServiceControl::class)->changePlan($client, $plan, 'automation');

                return "Plan de {$client->code} cambiado a {$plan->name}".($event->network_applied ? '' : ' ('.$event->network_result.')');

            case 'address_list':
                if (! $client) {
                    return 'Sin cliente';
                }
                $list = trim((string) ($params['list'] ?? ''));
                if ($list === '') {
                    throw new \RuntimeException('Falta el nombre de la address-list');
                }
                $remove = ($params['operation'] ?? 'add') === 'remove';
                [$applied, $result] = app(ServiceControl::class)->onRouter($client, fn (MikroTikService $mt) => $remove
                    ? $mt->removeFromAddressList($client, $list)
                    : $mt->addToAddressList($client, $list, mb_substr($client->code.' · '.$rule->name, 0, 120)));
                if (! $applied) {
                    throw new \RuntimeException($result);
                }

                return $result;

            case 'set_status':
                if (! $client || empty($params['status'])) {
                    return 'Sin cambios';
                }
                $client->update(['service_status' => $params['status']]);

                return 'Estado cambiado a '.$params['status'];
        }

        return "Acción desconocida: {$type}";
    }

    /** Sustituye {cliente}, {codigo}, {repetidor}, {afectados}, {zona} en textos de acciones. */
    public function interpolate(string $text, Model $subject, array $facts): string
    {
        $vars = [
            '{cliente}' => $subject instanceof Client ? $subject->full_name : ($subject->client->full_name ?? ''),
            '{codigo}' => $subject->code ?? $subject->folio ?? '',
            '{repetidor}' => $subject instanceof Repeater ? $subject->code.' '.$subject->name : ($subject->repeater->code ?? ''),
            '{afectados}' => (string) ($facts['affected_clients'] ?? ''),
            '{zona}' => $subject->zone->name ?? '',
            '{saldo}' => isset($facts['balance']) ? money($facts['balance']) : '',
            '{folio}' => $subject->folio ?? '',
        ];

        return strtr($text, $vars);
    }

    protected function record(AutomationRule $rule, Model $subject, string $status, array $log): AutomationRun
    {
        return AutomationRun::create([
            'tenant_id' => $rule->tenant_id,
            'automation_rule_id' => $rule->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'status' => $status,
            'log' => $log,
        ]);
    }
}
