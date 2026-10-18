<?php

namespace App\Services\Billing;

use App\Models\CashCut;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Control de efectivo (sección 27): cortes de caja por cobrador.
 *
 * Ciclo: open (recibiendo cobros) → cerrado (closed_at, se calcula lo esperado) →
 * delivered (efectivo entregado, diferencia) → reconciled (supervisor concilia).
 * Solo cuentan pagos en efectivo, aplicados (completed), con cobrador.
 */
class CashCutService
{
    /** Corte abierto (sin cerrar) del cobrador; lo crea si no existe y $create = true. */
    public function openCutFor(int $collectorId, bool $create = true): ?CashCut
    {
        $cut = CashCut::query()->where('collector_id', $collectorId)->where('status', 'open')
            ->whereNull('closed_at')->latest('id')->first();

        if (! $cut && $create) {
            $cut = CashCut::create([
                'tenant_id' => User::find($collectorId)?->tenant_id ?? tenant()?->id,
                'folio' => $this->folio($collectorId),
                'collector_id' => $collectorId,
                'opened_at' => now(),
                'status' => 'open',
            ]);
            Audit::log('cash_cut', "abrió corte de caja {$cut->folio}", $cut);
        }

        return $cut;
    }

    /** Asigna un cobro en efectivo al corte abierto de su cobrador. */
    public function attach(Payment $payment): ?CashCut
    {
        if ($payment->method !== 'efectivo' || ! $payment->collector_id || $payment->status !== 'completed' || $payment->cash_cut_id) {
            return null;
        }
        $cut = $this->openCutFor($payment->collector_id);
        $payment->forceFill(['cash_cut_id' => $cut->id])->saveQuietly();
        $this->recompute($cut);

        return $cut;
    }

    /**
     * Nuevo corte para un cobrador: si tiene uno abierto lo reutiliza; asigna sus cobros
     * en efectivo aún sin corte desde el último corte cerrado.
     */
    public function createFor(int $collectorId): CashCut
    {
        $existing = $this->openCutFor($collectorId, false);
        if ($existing) {
            $this->sweep($existing);

            return $existing->refresh();
        }

        $cut = CashCut::create([
            'tenant_id' => User::find($collectorId)?->tenant_id ?? tenant()?->id,
            'folio' => $this->folio($collectorId),
            'collector_id' => $collectorId,
            // Sin cortes previos: desde el cobro sin corte más antiguo de los últimos 31 días
            'opened_at' => $this->previousClose($collectorId, null)
                ?? (($first = $this->unassignedQuery($collectorId, now()->subDays(31)->startOfDay())->min('paid_at')) ? Carbon::parse($first) : now()),
            'status' => 'open',
        ]);
        $this->sweep($cut);
        Audit::log('cash_cut', "creó corte de caja {$cut->folio}", $cut);

        return $cut->refresh();
    }

    /** Cobros en efectivo del cobrador sin corte, desde el cierre de su corte anterior (o apertura de éste). */
    public function unassignedQuery(int $collectorId, ?Carbon $from = null)
    {
        return Payment::query()->where('collector_id', $collectorId)->where('method', 'efectivo')
            ->where('status', 'completed')->whereNull('cash_cut_id')
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from));
    }

    public function sweepFrom(CashCut $cut): Carbon
    {
        $prev = $this->previousClose($cut->collector_id, $cut->id);

        return $prev && $prev->lt($cut->opened_at) ? $prev : $cut->opened_at->copy();
    }

    /** Asigna al corte los cobros pendientes de corte de su cobrador. Devuelve cuántos asignó. */
    public function sweep(CashCut $cut): int
    {
        $n = $this->unassignedQuery($cut->collector_id, $this->sweepFrom($cut))
            ->where('paid_at', '<=', now())->update(['cash_cut_id' => $cut->id]);
        $this->recompute($cut);

        return $n;
    }

    /** Recalcula número de cobros, importe esperado y diferencia. */
    public function recompute(CashCut $cut): CashCut
    {
        $row = Payment::query()->where('cash_cut_id', $cut->id)->where('status', 'completed')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(amount), 0) total')->first();
        $expected = round((float) $row->total, 2);
        $cut->forceFill([
            'payments_count' => (int) $row->n,
            'expected_amount' => $expected,
            'difference' => $cut->delivered_amount !== null ? round((float) $cut->delivered_amount - $expected, 2) : null,
        ])->saveQuietly();

        return $cut;
    }

    /** Cerrar corte: asigna cobros pendientes y fija el importe esperado. $notes se agrega a las notas. */
    public function close(CashCut $cut, ?string $notes = null): CashCut
    {
        if ($cut->status !== 'open' || $cut->closed_at) {
            return $cut;
        }
        DB::transaction(function () use ($cut, $notes) {
            $this->sweep($cut);
            $cut->forceFill(['closed_at' => now(), 'notes' => $this->appendNote($cut->notes, $notes)])->save();
        });
        Audit::log('cash_cut', "cerró corte de caja {$cut->folio}: esperado ".money($cut->expected_amount), $cut);

        return $cut;
    }

    /** Registrar entrega de efectivo del cobrador. */
    public function deliver(CashCut $cut, float $delivered, ?string $notes = null): CashCut
    {
        if ($cut->status === 'reconciled') {
            throw new \RuntimeException('El corte ya está conciliado.');
        }
        if (! $cut->closed_at) {
            $this->close($cut);
        }
        $this->recompute($cut);
        $cut->forceFill([
            'delivered_amount' => round($delivered, 2),
            'difference' => round($delivered - (float) $cut->expected_amount, 2),
            'status' => 'delivered',
            'notes' => $this->appendNote($cut->notes, $notes),
        ])->save();
        Audit::log('cash_cut', "registró entrega de {$cut->folio}: ".money($delivered).' (diferencia '.money($cut->difference).')', $cut);

        return $cut;
    }

    /** Conciliar (supervisor): cobros registrados vs efectivo entregado. */
    public function reconcile(CashCut $cut, User $by, ?string $notes = null): CashCut
    {
        if ($cut->status !== 'delivered') {
            throw new \RuntimeException('Solo se pueden conciliar cortes con entrega registrada.');
        }
        $this->recompute($cut);
        $cut->forceFill([
            'status' => 'reconciled',
            'reconciled_by' => $by->id,
            'reconciled_at' => now(),
            'notes' => $this->appendNote($cut->notes, $notes),
        ])->save();
        Audit::log('cash_cut', "concilió corte de caja {$cut->folio} (diferencia ".money($cut->difference).')', $cut);

        return $cut;
    }

    /** Tras cancelar un pago en efectivo: corrige el corte si aún no está conciliado. */
    public function onPaymentCancelled(Payment $payment): void
    {
        if ($payment->cash_cut_id && ($cut = CashCut::find($payment->cash_cut_id)) && $cut->status !== 'reconciled') {
            $this->recompute($cut);
        }
    }

    protected function previousClose(int $collectorId, ?int $exceptId): ?Carbon
    {
        $value = CashCut::query()->where('collector_id', $collectorId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereNotNull('closed_at')->max('closed_at');

        return $value ? Carbon::parse($value) : null;
    }

    protected function folio(int $collectorId): string
    {
        $base = 'CC-'.$collectorId.'-'.now()->format('oW');
        $folio = $base;
        $i = 2;
        while (CashCut::withoutGlobalScopes()->where('folio', $folio)->exists()) {
            $folio = $base.'-'.$i++;
        }

        return $folio;
    }

    protected function appendNote(?string $current, ?string $note): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return $current;
        }
        $line = now()->format('d/m/Y H:i').' '.(auth()->user()?->name ?? 'Sistema').': '.$note;

        return mb_substr(trim(($current ? $current."\n" : '').$line), 0, 2000);
    }
}
