<?php

namespace App\Services\Billing;

use App\Models\Charge;
use App\Models\Client;
use App\Models\PaymentLink;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\AutomationEngine;
use App\Services\BillingService;
use App\Services\Messaging\MessageService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cobranza automática (secciones 6.2 y 8). Lógica compartida por las tareas
 * programadas (billing:*) y los botones de la pantalla de Cobranza.
 * Todas las operaciones son idempotentes: se pueden ejecutar repetidamente.
 */
class CollectionAutomation
{
    public function __construct(protected BillingService $billing) {}

    /** Fecha/hora actual en la zona horaria del WISP. */
    public function now(Tenant $tenant): Carbon
    {
        return now($tenant->timezone ?: config('app.timezone'));
    }

    /** Genera las mensualidades del día. */
    public function generateCharges(Tenant $tenant, ?Carbon $date = null, bool $dryRun = false): array
    {
        $date ??= Carbon::parse($this->now($tenant)->toDateString());

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $date, $dryRun) {
            $created = $dryRun ? 0 : $this->billing->generateCharges($tenant, $date);
            $stats = ['date' => $date->toDateString(), 'charges' => $created];
            $dryRun || $this->remember($tenant, 'generate', $stats);

            return $stats;
        });
    }

    /**
     * Recordatorios y avisos:
     *  - reminder: N días antes del vencimiento → evento payment_due_soon (una vez por cargo)
     *  - due: el día del vencimiento → mensaje "due" (una vez por cargo)
     *  - pending: N días después del vencimiento sin pagar → mensaje "pending" (billing.pending_notice_days)
     *  - suspension_warning: último día de tolerancia (la del plan o la del WISP) → mensaje (si hay corte automático)
     *  - marca morosos (activo con cargos vencidos) recalculando su estado de cuenta
     */
    public function sendReminders(Tenant $tenant, bool $force = false, bool $dryRun = false): array
    {
        $now = $this->now($tenant);
        $stats = ['reminders' => 0, 'due' => 0, 'pending' => 0, 'warnings' => 0, 'morosos' => 0];
        if (! $force && $now->format('H:i') < (string) $tenant->setting('billing.reminder_hour', '09:00')) {
            return $stats + ['skipped' => 'Antes de la hora de envío ('.$tenant->setting('billing.reminder_hour', '09:00').')'];
        }

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $now, $stats, $dryRun) {
            $today = $now->toDateString();
            $days = max(0, (int) $tenant->setting('billing.reminder_days_before', 3));
            [, $maxGrace] = $this->graceRange($tenant);
            $engine = app(AutomationEngine::class);
            $messages = app(MessageService::class);

            // 1) Recordatorio previo al vencimiento
            if ($days > 0) {
                $stats['reminders'] = $this->perClient(
                    $this->openCharges()->whereNull('charges.reminder_sent_at')
                        ->whereBetween('charges.due_date', [$now->copy()->addDay()->toDateString(), $now->copy()->addDays($days)->toDateString()]),
                    ['activo', 'moroso', 'en_revision'], 'reminder_sent_at', $dryRun,
                    fn (Client $c, $charges) => $engine->fire('payment_due_soon', $c, [
                        'amount' => $charges->sum(fn ($ch) => $ch->balance()),
                        'due_date' => $charges->min('due_date')?->toDateString(),
                        'days_left' => (int) $now->copy()->startOfDay()->diffInDays($charges->min('due_date')),
                    ]),
                );
            }

            // 2) Aviso de vencimiento (hoy)
            $stats['due'] = $this->perClient(
                $this->openCharges()->whereNull('charges.due_notice_sent_at')->whereDate('charges.due_date', $today),
                ['activo', 'moroso', 'en_revision'], 'due_notice_sent_at', $dryRun,
                fn (Client $c, $charges) => $messages->notify($c, 'due', ['monto' => money($charges->sum(fn ($ch) => $ch->balance()))]),
            );

            // 3) Aviso de pago pendiente (N días después de la fecha límite; solo cargos recientes
            //    para no avisar de golpe adeudos viejos)
            $pendingDays = max(0, (int) $tenant->setting('billing.pending_notice_days', 1));
            if ($pendingDays > 0) {
                $stats['pending'] = $this->perClient(
                    $this->openCharges()->whereNull('charges.pending_notice_sent_at')
                        ->whereDate('charges.due_date', '<=', $now->copy()->subDays($pendingDays)->toDateString())
                        ->whereDate('charges.due_date', '>=', $now->copy()->subDays($pendingDays + 7)->toDateString()),
                    ['activo', 'moroso', 'en_revision'], 'pending_notice_sent_at', $dryRun,
                    fn (Client $c) => $messages->notify($c, 'pending'),
                );
            }

            // 4) Aviso de suspensión (último día de tolerancia, con la tolerancia del plan del cliente)
            if ($tenant->setting('billing.auto_suspend', true)) {
                $stats['warnings'] = $this->perClient(
                    $this->openCharges()->whereNull('charges.suspension_warning_sent_at')
                        ->whereDate('charges.due_date', '>=', $now->copy()->subDays($maxGrace + 2)->toDateString())
                        ->whereDate('charges.due_date', '<', $today),
                    ['activo', 'moroso'], 'suspension_warning_sent_at', $dryRun,
                    fn (Client $c) => $messages->notify($c, 'suspension_warning'),
                    fn (Client $c, $charges) => $charges->min('due_date')->toDateString() <= $now->copy()->subDays($this->graceFor($c, $tenant))->toDateString(),
                );
            }

            // 5) Activos con cargos vencidos → moroso
            $toFlag = Client::query()->where('service_status', 'activo')
                ->whereExists(fn ($q) => $q->from('charges')->whereColumn('charges.client_id', 'clients.id')
                    ->whereIn('charges.status', ['pending', 'partial'])->where('charges.due_date', '<', $today))
                ->get();
            foreach ($toFlag as $client) {
                if (! $dryRun) {
                    $this->billing->recalculate($client);
                }
                $stats['morosos'] += $dryRun || $client->service_status === 'moroso' ? 1 : 0;
            }

            $dryRun || $this->remember($tenant, 'reminders', $stats);

            return $stats;
        });
    }

    /**
     * Corte automático: a partir de la hora de corte, clientes activos/morosos cuyo cargo
     * pendiente más antiguo superó la fecha límite + días de tolerancia → evento
     * payment_overdue (la regla del sistema suspende, aplica recargo y avisa). Una vez por cliente por día.
     */
    public function enforceCutoffs(Tenant $tenant, bool $force = false, bool $dryRun = false): array
    {
        $now = $this->now($tenant);
        $stats = ['evaluated' => 0, 'fired' => 0, 'suspended' => 0];
        if (! $tenant->setting('billing.auto_suspend', true)) {
            return $stats + ['skipped' => 'Corte automático desactivado'];
        }
        $cutHour = (string) $tenant->setting('billing.cut_hour', '10:00');
        if (! $force && $now->format('H:i') < $cutHour) {
            return $stats + ['skipped' => "Antes de la hora de corte ({$cutHour})"];
        }

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $now, $stats, $dryRun) {
            $today = $now->toDateString();
            // Primero con la tolerancia más corta (plan o WISP); luego se revisa la de cada cliente.
            [$minGrace] = $this->graceRange($tenant);
            $limit = $now->copy()->subDays($minGrace)->toDateString();
            $engine = app(AutomationEngine::class);

            $clients = Client::query()->whereIn('service_status', ['activo', 'moroso'])
                ->where(fn ($q) => $q->whereNull('overdue_fired_on')->orWhere('overdue_fired_on', '<', $today))
                ->whereExists(fn ($q) => $q->from('charges')->whereColumn('charges.client_id', 'clients.id')
                    ->whereIn('charges.status', ['pending', 'partial'])->where('charges.due_date', '<', $limit))
                ->get();

            foreach ($clients as $client) {
                $oldest = $client->charges()->whereIn('status', ['pending', 'partial'])->min('due_date');
                if (! $oldest || Carbon::parse($oldest)->toDateString() >= $now->copy()->subDays($this->graceFor($client, $tenant))->toDateString()) {
                    continue;
                }
                $stats['evaluated']++;
                if ($dryRun) {
                    continue;
                }
                // Se marca antes de disparar: si una acción falla no se reintenta el mismo día.
                $client->forceFill(['overdue_fired_on' => $today])->saveQuietly();
                $engine->fire('payment_overdue', $client, [
                    'overdue_days' => $this->billing->overdueDays($client),
                    'amount' => (float) $client->balance,
                ]);
                $stats['fired']++;
                if ($client->fresh()->service_status === 'suspendido') {
                    $stats['suspended']++;
                }
            }

            $dryRun || $this->remember($tenant, 'cutoffs', $stats);

            return $stats;
        });
    }

    /** Marca como expirados los enlaces de pago vencidos (todos los WISP). */
    public function expireLinks(bool $dryRun = false): int
    {
        $q = PaymentLink::withoutGlobalScopes()->where('status', 'pending')
            ->whereNotNull('expires_at')->where('expires_at', '<', now());

        return $dryRun ? $q->count() : $q->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /** Última ejecución de cada tarea (para mostrar en la pantalla de Cobranza). */
    public function lastRuns(Tenant $tenant): array
    {
        return collect(['generate', 'reminders', 'cutoffs'])
            ->mapWithKeys(fn ($k) => [$k => Cache::get("billing:last-run:{$tenant->id}:{$k}")])->all();
    }

    /** Días de tolerancia del cliente: los de su plan o, si el plan no define, los del WISP. */
    public function graceFor(Client $client, Tenant $tenant): int
    {
        return max(0, (int) ($client->plan?->grace_days ?? $tenant->setting('billing.grace_days', 3)));
    }

    /** Tolerancia mínima y máxima entre la del WISP y la de sus planes. */
    protected function graceRange(Tenant $tenant): array
    {
        $values = Plan::query()->whereNotNull('grace_days')->pluck('grace_days')
            ->push((int) $tenant->setting('billing.grace_days', 3))->map(fn ($v) => max(0, (int) $v));

        return [$values->min(), $values->max()];
    }

    protected function remember(Tenant $tenant, string $task, array $stats): void
    {
        Cache::put("billing:last-run:{$tenant->id}:{$task}", ['at' => now()->toIso8601String(), 'stats' => $stats], now()->addDays(40));
    }

    protected function openCharges()
    {
        return Charge::query()->select('charges.*')->whereIn('charges.status', ['pending', 'partial']);
    }

    /**
     * Agrupa cargos por cliente, ejecuta la acción una sola vez por cliente y marca
     * la columna de control en todos los cargos procesados.
     */
    protected function perClient($chargeQuery, array $statuses, string $markColumn, bool $dryRun, callable $action, ?callable $when = null): int
    {
        $charges = $chargeQuery->join('clients', 'clients.id', '=', 'charges.client_id')
            ->whereNull('clients.deleted_at')->whereIn('clients.service_status', $statuses)->get();
        if ($dryRun) {
            return $charges->pluck('client_id')->unique()->count();
        }

        $count = 0;
        foreach ($charges->groupBy('client_id') as $clientId => $group) {
            $client = Client::find($clientId);
            // $when: el cliente aún no cumple la condición (p. ej. su plan tiene más tolerancia).
            if (! $client || ($when && ! $when($client, $group))) {
                continue;
            }
            // Marcar primero (idempotencia aun si el envío falla)
            DB::table('charges')->whereIn('id', $group->pluck('id'))->update([$markColumn => now()]);
            try {
                $action($client, $group);
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }
}
