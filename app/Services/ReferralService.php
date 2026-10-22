<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Referral;
use Illuminate\Support\Str;

/**
 * Programa de referidos: código por cliente, registro del referido, validación
 * con el primer pago y entrega de la recompensa configurada por el WISP.
 */
class ReferralService
{
    public function ensureCode(Client $client): string
    {
        if ($client->referral_code) {
            return $client->referral_code;
        }
        $prefix = $client->tenant->setting('referrals.code_prefix', 'INVITA');
        do {
            $code = $prefix.'-'.random_int(1000, 9999);
        } while (Client::withoutGlobalScopes()->where('referral_code', $code)->exists());
        $client->forceFill(['referral_code' => $code])->saveQuietly();

        return $code;
    }

    public function findReferrer(string $code): ?Client
    {
        return Client::query()->where('referral_code', strtoupper(trim($code)))->first();
    }

    /** Registra que $newClient fue referido por el dueño del código. */
    public function register(Client $newClient, string $code, ?int $prospectId = null): ?Referral
    {
        $referrer = $this->findReferrer($code);
        if (! $referrer || $referrer->id === $newClient->id) {
            return null;
        }
        $newClient->forceFill(['referred_by_client_id' => $referrer->id])->saveQuietly();
        $cfg = $newClient->tenant->settingsFor('referrals');

        return Referral::firstOrCreate(
            ['referrer_client_id' => $referrer->id, 'referred_client_id' => $newClient->id],
            [
                'tenant_id' => $newClient->tenant_id,
                'code' => $referrer->referral_code,
                'prospect_id' => $prospectId,
                'status' => 'pendiente',
                'reward_type' => $cfg['reward_type'],
                'reward_value' => (float) $cfg['reward_value'],
            ]
        );
    }

    /** Al confirmarse el primer pago del referido se valida y recompensa. */
    public function onFirstPayment(Client $client): void
    {
        if (! $client->referred_by_client_id) {
            return;
        }
        $referral = Referral::query()->where('referred_client_id', $client->id)->where('status', 'pendiente')->first();
        if (! $referral) {
            return;
        }
        $payments = Payment::query()->where('client_id', $client->id)->revenue()->count();
        if ($payments < 1) {
            return;
        }
        $referral->update(['status' => 'validado', 'validated_at' => now()]);
        $this->reward($referral);
    }

    public function reward(Referral $referral): void
    {
        $referrer = $referral->referrer;
        if (! $referrer || $referral->status === 'recompensado') {
            return;
        }
        // Descuento (%) y promoción rebajan la siguiente mensualidad; mes gratis y saldo se abonan como pago.
        if (in_array($referral->reward_type, ['descuento', 'promocion'], true)) {
            $amount = $this->discountAmount($referral, $referrer);
            if ($amount > 0) {
                $referrer->increment('pending_discount', $amount);
            }
            $referral->update(['status' => 'recompensado', 'rewarded_at' => now(), 'reward_value' => $amount]);

            return;
        }
        $amount = match ($referral->reward_type) {
            'mes_gratis' => (float) ($referrer->plan?->price ?? 0),
            default => (float) $referral->reward_value,
        };
        if ($amount > 0) {
            app(BillingService::class)->registerPayment($referrer, $amount, 'referido', [
                'notify' => false,
                'notes' => 'Recompensa por referir a '.($referral->referred?->full_name ?? 'nuevo cliente'),
                'received_by' => null,
            ]);
        }
        $referral->update(['status' => 'recompensado', 'rewarded_at' => now(), 'reward_value' => $amount]);
    }

    /**
     * Importe del descuento: "descuento" guarda el porcentaje sobre el precio del plan; "promocion"
     * guarda el id de la promoción elegida en el programa de referidos.
     */
    protected function discountAmount(Referral $referral, Client $referrer): float
    {
        $price = (float) ($referrer->plan?->price ?? 0);
        if ($referral->reward_type === 'descuento') {
            return round($price * min(100, (float) $referral->reward_value) / 100, 2);
        }
        $promo = Promotion::query()->find((int) $referral->reward_value);

        return round(match ($promo?->type) {
            'descuento_pct' => $price * min(100, (float) $promo->value) / 100 * max(1, (int) $promo->months),
            'descuento_monto' => (float) $promo->value * max(1, (int) $promo->months),
            'mes_gratis' => $price * max(1, (int) $promo->months),
            default => 0,
        }, 2);
    }

    public function shareUrl(Client $client): string
    {
        return url('/r/'.Str::lower($this->ensureCode($client)));
    }
}
