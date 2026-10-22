<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\SlaPolicy;
use App\Models\Ticket;

/**
 * Vigilancia de SLA (sección 22). Se ejecuta por WISP dentro de su contexto
 * (comando operations:check-sla cada 5 minutos):
 *   - tickets abiertos que alcanzan el % de aviso  → sla_warned, alerta 'sla_risk', regla 'ticket_sla_risk'
 *   - tickets abiertos con SLA vencido              → sla_breached, alerta 'sla_breached'
 *   - tickets cerrados/resueltos                     → se resuelven sus alertas de SLA
 */
class SlaMonitor
{
    public function __construct(protected AlertService $alerts, protected AutomationEngine $automation) {}

    /** @return array{warned:int, breached:int, resolved:int} */
    public function check(): array
    {
        $stats = ['warned' => 0, 'breached' => 0, 'resolved' => 0];
        $warn = SlaPolicy::query()->whereNull('category')->pluck('warn_percent', 'priority');

        $tickets = Ticket::query()->open()->whereNotNull('sla_due_at')
            ->with('client:id,first_name,last_name', 'repeater:id,code')
            ->get();

        foreach ($tickets as $ticket) {
            $who = $ticket->client?->full_name ?? ($ticket->repeater ? 'Repetidor '.$ticket->repeater->code : 'Sin cliente');

            if ($ticket->sla_due_at->isPast()) {
                if (! $ticket->sla_breached) {
                    $ticket->forceFill(['sla_breached' => true, 'sla_warned' => true])->saveQuietly();
                    $ticket->addComment('SLA vencido ('.$ticket->sla_due_at->format('d/m/Y H:i').')', 'system');
                    $stats['breached']++;
                }
                $this->alerts->raise('sla_breached', 'critical', "Ticket {$ticket->folio} con SLA vencido",
                    "{$ticket->subject} · {$who}. Venció ".$ticket->sla_due_at->diffForHumans().'.', $ticket,
                    ['folio' => $ticket->folio, 'priority' => $ticket->priority]);
                $this->alerts->resolveFor('sla_risk', $ticket);

                continue;
            }

            $total = max(1, $ticket->created_at->diffInMinutes($ticket->sla_due_at));
            $elapsed = $ticket->created_at->diffInMinutes(now());
            $threshold = (int) ($warn[$ticket->priority] ?? 75);

            if ($elapsed / $total * 100 >= $threshold && ! $ticket->sla_warned) {
                $ticket->forceFill(['sla_warned' => true])->saveQuietly();
                $minutesLeft = (int) now()->diffInMinutes($ticket->sla_due_at);
                $this->alerts->raise('sla_risk', $ticket->priority === 'critica' ? 'critical' : 'warning',
                    "Ticket {$ticket->folio} próximo a vencer",
                    "{$ticket->subject} · {$who}. Restan {$minutesLeft} min del SLA.", $ticket,
                    ['folio' => $ticket->folio, 'priority' => $ticket->priority, 'minutes_left' => $minutesLeft]);
                $this->automation->fire('ticket_sla_risk', $ticket, ['minutes_left' => $minutesLeft]);
                $stats['warned']++;
            }
        }

        // Resolver alertas de tickets que ya no están abiertos
        $alerts = Alert::query()->open()->whereIn('type', ['sla_risk', 'sla_breached'])->where('subject_type', 'ticket')->get();
        if ($alerts->isNotEmpty()) {
            $closed = Ticket::query()->whereIn('id', $alerts->pluck('subject_id')->unique())
                ->whereNotIn('status', Ticket::OPEN_STATUSES)->pluck('id')->all();
            foreach ($alerts->whereIn('subject_id', $closed) as $alert) {
                $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
                $stats['resolved']++;
            }
        }

        return $stats;
    }

    /** Recalcula la fecha objetivo de los tickets abiertos con las políticas vigentes. */
    public function recalculateOpen(): int
    {
        $count = 0;
        Ticket::query()->open()->each(function (Ticket $t) use (&$count) {
            $due = $t->created_at->copy()->addMinutes(SlaPolicy::minutesFor($t->priority, $t->category));
            $t->forceFill([
                'sla_due_at' => $due,
                'sla_warned' => $t->sla_warned && $due->isPast(),
                'sla_breached' => $due->isPast(),
            ])->saveQuietly();
            $count++;
        });

        return $count;
    }
}
