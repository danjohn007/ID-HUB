<?php

namespace App\Models;

class PaymentGateway extends TenantModel
{
    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'enabled' => 'boolean',
        'sandbox' => 'boolean',
    ];

    public function credential(string $key, mixed $default = null): mixed
    {
        return ($this->credentials ?? [])[$key] ?? $default;
    }

    public function feeFor(float $amount): float
    {
        return round($amount * (float) $this->fee_percent / 100 + (float) $this->fee_fixed, 2);
    }
}
