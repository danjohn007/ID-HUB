<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ServiceEvent;
use Illuminate\Support\Facades\DB;

/**
 * Indicador de riesgo de morosidad basado en comportamiento histórico (12 meses).
 * Es un indicador orientativo, no una garantía de incumplimiento.
 *
 * Variables: días promedio de retraso, número de retrasos, cargos vencidos,
 * frecuencia de reconexiones, historial de suspensión y monto pendiente.
 */
class RiskService
{
    public function evaluate(Client $client): array
    {
        $since = now()->subYear()->toDateString();

        $charges = DB::table('charges')->where('client_id', $client->id)->where('type', 'mensualidad')
            ->where('due_date', '>=', $since)->where('status', '!=', 'cancelled')
            ->get(['due_date', 'paid_at', 'status']);

        $lateDays = [];
        $lateCount = 0;
        foreach ($charges as $c) {
            if ($c->paid_at) {
                $days = max(0, (int) floor((strtotime(substr($c->paid_at, 0, 10)) - strtotime($c->due_date)) / 86400));
            } elseif ($c->due_date < now()->toDateString()) {
                $days = (int) floor((time() - strtotime($c->due_date)) / 86400);
            } else {
                continue;
            }
            $lateDays[] = $days;
            if ($days > 0) {
                $lateCount++;
            }
        }
        $avgDelay = $lateDays ? array_sum($lateDays) / count($lateDays) : 0;
        $overdue = $charges->filter(fn ($c) => ! $c->paid_at && $c->due_date < now()->toDateString())->count();
        $events = ServiceEvent::withoutGlobalScopes()->where('client_id', $client->id)->where('created_at', '>=', $since)
            ->selectRaw('type, COUNT(*) as n')->groupBy('type')->pluck('n', 'type');
        $suspensions = (int) ($events['suspension'] ?? 0);
        $reconnections = (int) ($events['reconnection'] ?? 0);
        $balance = (float) $client->balance;
        $price = max(1, (float) ($client->plan?->price ?? 350));

        // Ponderación (0-100)
        $score = min(25, $avgDelay * 2.2)               // días promedio de retraso
            + min(20, $lateCount * 2.5)                 // número de retrasos
            + min(20, $overdue * 10)                    // cargos vencidos actuales
            + min(15, $suspensions * 5)                 // suspensiones
            + min(8, $reconnections * 2.5)              // reconexiones
            + min(12, $balance / $price * 6);           // monto pendiente relativo a la mensualidad
        $score = (int) round(min(100, $score));

        return [
            'score' => $score,
            'level' => $score >= 65 ? 'alto' : ($score >= 35 ? 'medio' : 'bajo'),
            'factors' => [
                'Días promedio de retraso' => round($avgDelay, 1),
                'Pagos con retraso (12m)' => $lateCount,
                'Cargos vencidos' => $overdue,
                'Suspensiones (12m)' => $suspensions,
                'Reconexiones (12m)' => $reconnections,
                'Monto pendiente' => money($balance),
            ],
        ];
    }

    /** Recalcula y guarda; alerta cuando un cliente pasa a riesgo alto. */
    public function refresh(Client $client): array
    {
        $result = $this->evaluate($client);
        $wasHigh = $client->risk_level === 'alto';
        $client->forceFill(['risk_score' => $result['score'], 'risk_level' => $result['level']])->saveQuietly();

        if ($result['level'] === 'alto' && ! $wasHigh && in_array($client->service_status, Client::BILLABLE, true)) {
            app(AlertService::class)->raise('recurrent_delay', 'warning', 'Cliente con alta probabilidad de retraso en próximo pago',
                "{$client->full_name} ({$client->code}) · puntaje {$result['score']}/100", $client);
            app(AutomationEngine::class)->fire('high_risk_client', $client, ['risk_score' => $result['score']]);
        }

        return $result;
    }
}
