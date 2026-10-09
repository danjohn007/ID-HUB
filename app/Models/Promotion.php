<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;

class Promotion extends TenantModel
{
    use Auditable;

    protected $casts = [
        'plan_ids' => 'array',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'show_in_portal' => 'boolean',
        'active' => 'boolean',
    ];

    public function scopeCurrent(Builder $q): Builder
    {
        $today = now()->toDateString();

        return $q->where('active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $today));
    }

    public function valueLabel(): string
    {
        return match ($this->type) {
            'descuento_pct' => (float) $this->value.'% de descuento',
            'descuento_monto' => money($this->value).' de descuento',
            'mes_gratis' => $this->months.' mes(es) gratis',
            'instalacion_gratis' => 'Instalación gratis',
            default => '',
        };
    }

    public function auditLabel(): string
    {
        return 'promoción '.$this->name;
    }
}
