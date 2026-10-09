<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends TenantModel
{
    use Auditable;

    protected $casts = [
        'price_includes_tax' => 'boolean',
        'auto_reconnect' => 'boolean',
        'active' => 'boolean',
        'public' => 'boolean',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function speedLabel(): string
    {
        return (float) $this->download_mbps.' Mbps';
    }

    public function periodMonths(): int
    {
        return (int) config("wisp.periodicity.{$this->periodicity}.1", 1);
    }

    public function periodicityLabel(): string
    {
        return config("wisp.periodicity.{$this->periodicity}.0", 'Mensual');
    }

    /** Desglose del precio en subtotal / IVA / total. */
    public function priceBreakdown(?float $price = null): array
    {
        $rate = (float) $this->tax_rate / 100;
        $price ??= (float) $this->price;
        if ($this->price_includes_tax) {
            $subtotal = round($price / (1 + $rate), 2);

            return ['subtotal' => $subtotal, 'tax' => round($price - $subtotal, 2), 'total' => round($price, 2)];
        }
        $tax = round($price * $rate, 2);

        return ['subtotal' => round($price, 2), 'tax' => $tax, 'total' => round($price + $tax, 2)];
    }

    public function auditLabel(): string
    {
        return 'plan '.$this->name;
    }
}
