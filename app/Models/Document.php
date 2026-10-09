<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends TenantModel
{
    protected $casts = ['signed_at' => 'datetime'];

    public const TYPES = [
        'contrato' => 'Contrato',
        'orden' => 'Orden de instalación',
        'recibo' => 'Recibo',
        'comprobante' => 'Comprobante',
        'evidencia' => 'Evidencia de servicio',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
