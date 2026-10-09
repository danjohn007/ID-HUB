<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends TenantModel
{
    protected $casts = ['sent_at' => 'datetime'];

    public const STATUS = [
        'queued' => ['En cola', 'slate'],
        'sent' => ['Enviado', 'success'],
        'delivered' => ['Entregado', 'success'],
        'failed' => ['Fallido', 'danger'],
        'simulated' => ['Simulado', 'info'],
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
