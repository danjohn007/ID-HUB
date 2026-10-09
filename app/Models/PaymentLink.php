<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentLink extends TenantModel
{
    protected $casts = ['expires_at' => 'datetime', 'paid_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (PaymentLink $link) {
            $link->token ??= Str::random(40);
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function url(): string
    {
        return route('pay.show', $this->token);
    }

    public function isPayable(): bool
    {
        return $this->status === 'pending' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
