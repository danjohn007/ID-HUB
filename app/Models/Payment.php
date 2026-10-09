<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends TenantModel
{
    use Auditable;

    protected $casts = ['paid_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function cashCut(): BelongsTo
    {
        return $this->belongsTo(CashCut::class);
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class);
    }

    /** Pagos reales de dinero (excluye notas de crédito / bonificaciones). */
    public function scopeRevenue(Builder $q): Builder
    {
        return $q->where('kind', 'payment')->where('status', 'completed');
    }

    public function methodLabel(): string
    {
        return config("wisp.payment_methods.{$this->method}", $this->method);
    }

    public function auditLabel(): string
    {
        return 'pago '.$this->folio;
    }
}
