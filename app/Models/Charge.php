<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Charge extends TenantModel
{
    use Auditable;

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending', 'partial']);
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->open()->where('due_date', '<', now()->toDateString());
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['pending', 'partial']) && $this->due_date->isPast() && ! $this->due_date->isToday();
    }

    public function auditLabel(): string
    {
        return 'cargo '.($this->folio ?: '#'.$this->id);
    }
}
