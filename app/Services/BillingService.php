<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Tenant;
use App\Services\Messaging\MessageService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Cobranza: generación de cargos, registro y aplicación de pagos (FIFO a cargos
 * más antiguos), saldo a favor, recargos, enlaces de pago y estado de cuenta.
 */
class BillingService
{
    public const ONLINE_METHODS = ['mercadopago', 'stripe', 'paypal', 'oxxo'];

    public const CREDIT_METHODS = ['saldo', 'descuento', 'bonificacion', 'referido'];

    /**
     * Genera las mensualidades del día para un WISP. Idempotente por periodo.
     * fixed_day: todos los clientes en el día de corte del WISP; anniversary: según billing_day del cliente.
     */
    public function generateCharges(Tenant $tenant, ?Carbon $date = null): int
    {
        $date = ($date ?? now())->copy()->startOfDay();
        $mode = $tenant->setting('billing.mode', 'fixed_day');
        $tenantDay = (int) $tenant->setting('billing.billing_day', 1);
        $lastDay = $date->daysInMonth;

        $query = Client::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereIn('service_status', ['activo', 'moroso', 'suspendido', 'en_revision'])
            ->whereNotNull('plan_id')->with('plan');

        if ($mode === 'fixed_day') {
            if (min($tenantDay, $lastDay) !== $date->day) {
                return 0;
            }
        } else {
            $query->where(function ($q) use ($date, $lastDay) {
                $q->where('billing_day', $date->day);
                if ($date->day === $lastDay) {
                    $q->orWhere('billing_day', '>', $lastDay);
                }
            });
        }

        $count = 0;
        $query->chunkById(200, function ($clients) use ($date, &$count) {
            foreach ($clients as $client) {
                if ($this->chargePeriod($client, $date)) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /** Crea la mensualidad del periodo que inicia en $start si no existe. */
    public function chargePeriod(Client $client, Carbon $start): ?Charge
    {
        $plan = $client->plan;
        if (! $plan) {
            return null;
        }
        $exists = Charge::withoutGlobalScopes()->where('client_id', $client->id)
            ->where('type', 'mensualidad')->whereDate('period_start', $start->toDateString())->exists();
        if ($exists) {
            return null;
        }

        $tenant = $client->tenant;
        $end = $start->copy()->addMonthsNoOverflow($plan->periodMonths())->subDay();
        // El precio del plan es lo que se cobra por periodo (mensual, trimestral...), como en el catálogo de planes.
        $price = $plan->priceBreakdown((float) $plan->price);
        // Descuento pendiente (recompensa de referidos): se descuenta de esta mensualidad hasta agotarse.
        $discount = min(round((float) $client->pending_discount, 2), $price['total']);
        if ($discount > 0) {
            $factor = ($price['total'] - $discount) / max(0.01, $price['total']);
            $price = ['subtotal' => round($price['subtotal'] * $factor, 2), 'tax' => round($price['tax'] * $factor, 2), 'total' => round($price['total'] - $discount, 2)];
            $client->forceFill(['pending_discount' => round((float) $client->pending_discount - $discount, 2)])->saveQuietly();
        }
        $charge = Charge::create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'folio' => $this->chargeFolio($client->tenant_id),
            'type' => 'mensualidad',
            'description' => "Servicio {$plan->name} ".$start->translatedFormat('M Y').($discount > 0 ? ' (descuento '.money($discount).')' : ''),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'subtotal' => $price['subtotal'],
            'tax' => $price['tax'],
            'total' => $price['total'],
            'due_date' => $start->copy()->addDays((int) $tenant->setting('billing.due_days', 5))->toDateString(),
            'status' => 'pending',
        ]);

        $this->applyCreditBalance($client);
        $this->recalculate($client);

        return $charge;
    }

    /** Cargo adicional (instalación, equipo, recargo, reconexión, otro). Importe con IVA incluido. */
    public function createCharge(Client $client, string $type, string $description, float $total, ?Carbon $dueDate = null, float $taxRate = 16): Charge
    {
        $subtotal = round($total / (1 + $taxRate / 100), 2);
        $charge = Charge::create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'folio' => $this->chargeFolio($client->tenant_id),
            'type' => $type,
            'description' => $description,
            'subtotal' => $subtotal,
            'tax' => round($total - $subtotal, 2),
            'total' => round($total, 2),
            'due_date' => ($dueDate ?? now())->toDateString(),
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);
        $this->applyCreditBalance($client);
        $this->recalculate($client);

        return $charge;
    }

    public function cancelCharge(Charge $charge, string $reason = ''): void
    {
        DB::transaction(function () use ($charge) {
            foreach ($charge->allocations as $alloc) {
                // El dinero aplicado vuelve como saldo a favor
                $charge->client->increment('credit_balance', $alloc->amount);
                $alloc->delete();
            }
            $charge->update(['status' => 'cancelled', 'paid_amount' => 0]);
        });
        Audit::log('cancel_charge', "canceló cargo {$charge->folio}".($reason ? " ({$reason})" : ''), $charge);
        $this->applyCreditBalance($charge->client);
        $this->recalculate($charge->client);
    }

    /** Recargo por pago tardío (uno por mes). */
    public function addLateFee(Client $client): ?Charge
    {
        $fee = (float) ($client->plan?->late_fee ?? $client->tenant->setting('billing.late_fee', 0));
        if ($fee <= 0) {
            return null;
        }
        $already = Charge::withoutGlobalScopes()->where('client_id', $client->id)->where('type', 'recargo')
            ->where('created_at', '>=', now()->startOfMonth())->exists();
        if ($already) {
            return null;
        }

        return $this->createCharge($client, 'recargo', 'Recargo por pago tardío', $fee, now());
    }

    /**
     * Registra un pago y lo aplica a los cargos pendientes más antiguos.
     * $attrs: reference, gateway, external_id, gateway_fee, received_by, collector_id, cash_cut_id,
     *         payment_link_id, evidence_path, notes, paid_at, lat, lng, notify (bool), status
     */
    public function registerPayment(Client $client, float $amount, string $method, array $attrs = []): Payment
    {
        $notify = $attrs['notify'] ?? true;
        unset($attrs['notify']);

        $payment = DB::transaction(function () use ($client, $amount, $method, $attrs) {
            $tenant = $client->tenant;
            $isCredit = in_array($method, self::CREDIT_METHODS, true);
            $gatewayFee = round((float) ($attrs['gateway_fee'] ?? 0), 2);
            $platformFee = in_array($method, self::ONLINE_METHODS, true) ? $this->platformFee($tenant, $amount) : 0;

            if ($method === 'saldo') {
                $client->decrement('credit_balance', min($amount, (float) $client->credit_balance));
            }

            $payment = Payment::create(array_merge([
                'tenant_id' => $client->tenant_id,
                'client_id' => $client->id,
                'folio' => next_folio('payments', $tenant->setting('billing.folio_prefix', 'REC')),
                'kind' => $isCredit ? 'credit' : 'payment',
                'method' => $method,
                'amount' => round($amount, 2),
                'gateway_fee' => $gatewayFee,
                'platform_fee' => $platformFee,
                'net_amount' => round($amount - $gatewayFee - $platformFee, 2),
                'status' => 'completed',
                'paid_at' => now(),
                'received_by' => auth()->id(),
            ], $attrs));

            if ($payment->status === 'completed') {
                $this->allocate($payment, $client);
            }

            return $payment;
        });

        $this->recalculate($client->refresh());
        Audit::log('payment', "registró pago {$payment->folio} de ".money($amount)." para cliente #{$client->code}", $payment);

        if ($payment->status === 'completed' && $payment->kind === 'payment') {
            if ($notify) {
                app(MessageService::class)->notify($client, 'payment_received', [
                    'monto' => money($amount), 'folio' => $payment->folio,
                    // Comprobante PDF por enlace firmado (sin iniciar sesión), vigente 60 días.
                    'link_comprobante' => URL::temporarySignedRoute('api.v1.receipt', now()->addDays(60), ['payment' => $payment->id]),
                ]);
            }
            app(AutomationEngine::class)->fire('payment_received', $client, ['amount' => $amount, 'folio' => $payment->folio, 'method' => $method]);
            app(ReferralService::class)->onFirstPayment($client);
        }

        return $payment;
    }

    /** Aplica el importe del pago a cargos abiertos (más antiguos primero); el excedente queda como saldo a favor. */
    protected function allocate(Payment $payment, Client $client): void
    {
        $remaining = (float) $payment->amount;
        $charges = Charge::withoutGlobalScopes()->where('client_id', $client->id)->open()
            ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();

        foreach ($charges as $charge) {
            if ($remaining <= 0.0049) {
                break;
            }
            $apply = min($remaining, $charge->balance());
            if ($apply <= 0) {
                continue;
            }
            $payment->allocations()->create(['charge_id' => $charge->id, 'amount' => round($apply, 2)]);
            $paid = round((float) $charge->paid_amount + $apply, 2);
            $charge->update([
                'paid_amount' => $paid,
                'status' => $paid >= (float) $charge->total - 0.0049 ? 'paid' : 'partial',
                'paid_at' => $paid >= (float) $charge->total - 0.0049 ? now() : null,
            ]);
            $remaining = round($remaining - $apply, 2);
        }

        if ($remaining > 0.0049 && $payment->method !== 'saldo') {
            $client->increment('credit_balance', $remaining);
        }
    }

    /** Usa el saldo a favor para cubrir cargos pendientes. */
    public function applyCreditBalance(Client $client): void
    {
        $client->refresh();
        $credit = (float) $client->credit_balance;
        $due = (float) Charge::withoutGlobalScopes()->where('client_id', $client->id)->open()->sum(DB::raw('total - paid_amount'));
        $use = round(min($credit, $due), 2);
        if ($use > 0.0049) {
            $this->registerPayment($client, $use, 'saldo', ['notify' => false, 'notes' => 'Aplicación automática de saldo a favor']);
        }
    }

    public function cancelPayment(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment) {
            foreach ($payment->allocations as $alloc) {
                $charge = $alloc->charge;
                $paid = max(0, round((float) $charge->paid_amount - (float) $alloc->amount, 2));
                $charge->update(['paid_amount' => $paid, 'status' => $paid > 0 ? 'partial' : 'pending', 'paid_at' => null]);
                $alloc->delete();
            }
            $applied = (float) $payment->allocations()->sum('amount');
            $excess = (float) $payment->amount - $applied;
            if ($payment->method !== 'saldo' && $excess > 0) {
                $payment->client->decrement('credit_balance', min($excess, (float) $payment->client->credit_balance));
            }
            if ($payment->method === 'saldo') {
                $payment->client->increment('credit_balance', $payment->amount);
            }
            $payment->update(['status' => 'cancelled']);
        });
        Audit::log('cancel_payment', "canceló pago {$payment->folio}: {$reason}", $payment);
        $this->recalculate($payment->client->refresh());
    }

    /** Recalcula saldo pendiente, próxima fecha de pago y estado moroso. */
    public function recalculate(Client $client): void
    {
        $open = Charge::withoutGlobalScopes()->where('client_id', $client->id)->open();
        $balance = round((float) (clone $open)->sum(DB::raw('total - paid_amount')), 2);
        $nextDue = (clone $open)->min('due_date');

        $status = $client->service_status;
        $hasOverdue = (clone $open)->where('due_date', '<', now()->toDateString())->exists();
        if ($status === 'activo' && $hasOverdue) {
            $status = 'moroso';
        } elseif ($status === 'moroso' && ! $hasOverdue) {
            $status = 'activo';
        }

        $client->forceFill([
            'balance' => $balance,
            'next_due_date' => $nextDue ?? $this->nextBillingDate($client)?->toDateString(),
            'service_status' => $status,
        ])->saveQuietly();
    }

    public function nextBillingDate(Client $client): ?Carbon
    {
        $tenant = $client->tenant;
        $day = $tenant->setting('billing.mode') === 'anniversary' ? (int) $client->billing_day : (int) $tenant->setting('billing.billing_day', 1);
        $date = now()->startOfDay();
        $candidate = $date->copy()->day(min($day, $date->daysInMonth));
        if ($candidate->lte($date)) {
            $next = $date->copy()->startOfMonth()->addMonthNoOverflow();
            $candidate = $next->day(min($day, $next->daysInMonth));
        }

        return $candidate->addDays((int) $tenant->setting('billing.due_days', 5));
    }

    /** Días de atraso del cargo pendiente más antiguo. */
    public function overdueDays(Client $client): int
    {
        $oldest = Charge::withoutGlobalScopes()->where('client_id', $client->id)->open()->min('due_date');

        return $oldest && Carbon::parse($oldest)->isPast() ? (int) Carbon::parse($oldest)->diffInDays(now()->startOfDay()) : 0;
    }

    /** Enlace "Pagar ahora" vigente por el saldo actual (lo reutiliza si existe). */
    public function paymentLinkFor(Client $client, ?float $amount = null): ?PaymentLink
    {
        $amount ??= (float) $client->balance;
        if ($amount <= 0) {
            return null;
        }
        $link = PaymentLink::withoutGlobalScopes()->where('client_id', $client->id)->where('status', 'pending')
            ->where('amount', round($amount, 2))->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();

        return $link ?? PaymentLink::create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'amount' => round($amount, 2),
            'concept' => 'Pago de servicio de Internet - '.$client->code,
            'status' => 'pending',
            'expires_at' => now()->addDays(10),
        ]);
    }

    /** Comisión de la plataforma SaaS sobre pagos en línea. */
    public function platformFee(Tenant $tenant, float $amount): float
    {
        $plan = $tenant->saasPlan;

        return $plan ? round($amount * (float) $plan->commission_percent / 100 + (float) $plan->commission_fixed, 2) : 0;
    }

    protected function chargeFolio(int $tenantId): string
    {
        return next_folio('charges', 'C', 'folio', 7);
    }
}
