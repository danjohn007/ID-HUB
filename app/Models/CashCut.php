<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashCut extends TenantModel
{
    use Auditable;

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public const STATUS = [
        'open' => ['Abierto', 'info'],
        'delivered' => ['Entregado', 'warning'],
        'reconciled' => ['Conciliado', 'success'],
    ];

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLabel(): string
    {
        return 'corte de caja '.$this->folio;
    }

    /** Estado para mostrar: un corte "open" con closed_at ya fue cerrado y espera la entrega del efectivo. */
    public function displayStatus(): string
    {
        return $this->status === 'open' && $this->closed_at ? 'closed' : $this->status;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
