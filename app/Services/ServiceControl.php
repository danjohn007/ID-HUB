<?php

namespace App\Services;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Plan;
use App\Models\ServiceEvent;
use App\Services\Messaging\MessageService;
use App\Services\MikroTik\MikroTikService;
use App\Support\Audit;

/**
 * Control del servicio de un cliente: activación, suspensión, reconexión,
 * cambio de plan y cancelación, aplicando el cambio en MikroTik y registrando
 * el evento, la auditoría y la notificación al cliente.
 */
class ServiceControl
{
    public function suspend(Client $client, string $reason = 'Falta de pago', string $source = 'manual', bool $notify = true): ServiceEvent
    {
        $blockType = $client->plan?->block_type ?: $client->tenant->setting('billing.block_type', 'total');
        [$applied, $result] = $this->onRouter($client, fn (MikroTikService $mt) => $mt->suspendClient($client, $blockType));

        $client->forceFill(['service_status' => 'suspendido', 'suspended_at' => now()])->save();
        $event = $this->event($client, 'suspension', $reason, $source, $applied, $result, ['block_type' => $blockType]);
        Audit::log('suspend', "suspendió cliente #{$client->code}", $client, [], ['motivo' => $reason]);

        if ($notify) {
            app(MessageService::class)->notify($client, 'suspended');
        }
        if ($source !== 'automation') {
            app(AutomationEngine::class)->fire('client_suspended', $client);
        }

        return $event;
    }

    public function reconnect(Client $client, string $reason = 'Pago recibido', string $source = 'manual', bool $notify = true): ServiceEvent
    {
        [$applied, $result] = $this->onRouter($client, fn (MikroTikService $mt) => $mt->reactivateClient($client));

        $client->forceFill(['service_status' => 'activo', 'suspended_at' => null])->save();
        $event = $this->event($client, 'reconnection', $reason, $source, $applied, $result);
        Audit::log('reconnect', "reactivó cliente #{$client->code}", $client, [], ['motivo' => $reason]);

        $fee = (float) ($client->plan?->reconnection_fee ?: $client->tenant->setting('billing.reconnection_fee', 0));
        if ($fee > 0 && $source !== 'manual_nofee') {
            app(BillingService::class)->createCharge($client, 'reconexion', 'Cargo por reconexión', $fee, ($client->next_due_date ?? now()->addDays(5))->copy());
        }

        if ($notify) {
            app(MessageService::class)->notify($client, 'reconnected');
        }

        return $event;
    }

    /** Alta en red y activación de un cliente nuevo. */
    public function activate(Client $client, bool $notify = true): ServiceEvent
    {
        [$applied, $result] = $this->onRouter($client, fn (MikroTikService $mt) => $mt->provisionClient($client));

        $client->forceFill([
            'service_status' => 'activo',
            'installation_date' => $client->installation_date ?? now()->toDateString(),
        ])->save();
        $event = $this->event($client, 'activation', 'Activación de servicio', 'manual', $applied, $result);

        if ($notify) {
            app(MessageService::class)->notify($client, 'welcome');
        }
        app(AutomationEngine::class)->fire('client_created', $client);

        return $event;
    }

    public function changePlan(Client $client, Plan $plan, string $source = 'manual'): ServiceEvent
    {
        $old = $client->plan;
        $client->plan()->associate($plan)->save();
        [$applied, $result] = $this->onRouter($client, fn (MikroTikService $mt) => $mt->applyPlan($client, $plan));

        return $this->event($client, 'plan_change', ($old?->name ?? '—').' → '.$plan->name, $source, $applied, $result, ['from' => $old?->id, 'to' => $plan->id]);
    }

    public function cancel(Client $client, string $reason, string $source = 'manual'): ServiceEvent
    {
        [$applied, $result] = $this->onRouter($client, function (MikroTikService $mt) use ($client) {
            $mt->removeClient($client);

            return 'Usuario eliminado del equipo';
        });
        $client->forceFill(['service_status' => 'cancelado', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        Audit::log('cancel', "canceló cliente #{$client->code}", $client, [], ['motivo' => $reason]);

        return $this->event($client, 'cancellation', $reason, $source, $applied, $result);
    }

    /** MikroTik responsable del cliente (propio o el del repetidor). */
    public function routerFor(Client $client): ?NetworkDevice
    {
        return $client->router ?? $client->repeater?->router;
    }

    /** Ejecuta una operación en el MikroTik del cliente; nunca lanza excepción. */
    public function onRouter(Client $client, callable $operation): array
    {
        $router = $this->routerFor($client);
        if (! $router) {
            return [false, 'Sin MikroTik asignado: cambio solo administrativo'];
        }
        try {
            $mt = MikroTikService::for($router);
            $result = $operation($mt);
            if ($mt->isDeferred()) {
                $result .= ' (en cola para el agente push)';
            }

            return [true, $result];
        } catch (\Throwable $e) {
            $router->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 190)])->saveQuietly();
            app(AlertService::class)->raise('device_offline', 'warning', "No se pudo aplicar cambio en {$router->name}", $e->getMessage(), $router, [], 'router_error:'.$router->id);

            return [false, 'Error MikroTik: '.$e->getMessage()];
        }
    }

    protected function event(Client $client, string $type, string $reason, string $source, bool $applied, string $result, array $data = []): ServiceEvent
    {
        return ServiceEvent::create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'type' => $type,
            'reason' => mb_substr($reason, 0, 190),
            'source' => $source,
            'user_id' => auth()->id(),
            'network_applied' => $applied,
            'network_result' => mb_substr($result, 0, 190),
            'data' => $data ?: null,
        ]);
    }
}
