<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends TenantModel
{
    protected $casts = ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];

    public const STATUS = [
        'draft' => ['Borrador', 'slate'],
        'scheduled' => ['Programada', 'info'],
        'sending' => ['Enviando', 'warning'],
        'sent' => ['Enviada', 'success'],
        'cancelled' => ['Cancelada', 'slate'],
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
