<?php

namespace App\Models;

class CaptiveVoucher extends TenantModel
{
    protected $casts = ['used_at' => 'datetime', 'expires_at' => 'datetime'];

    public const STATUS = [
        'available' => ['Disponible', 'success'],
        'used' => ['Usado', 'info'],
        'expired' => ['Expirado', 'slate'],
    ];
}
