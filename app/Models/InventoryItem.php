<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends TenantModel
{
    use Auditable;

    protected $auditExclude = ['stock'];

    protected $casts = ['serialized' => 'boolean', 'active' => 'boolean'];

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    public function scopeLowStock(Builder $q): Builder
    {
        return $q->whereColumn('stock', '<=', 'min_stock')->where('active', true);
    }

    public function stockState(): string
    {
        return match (true) {
            $this->stock <= 0 => 'danger',
            $this->stock <= $this->min_stock => 'warning',
            $this->max_stock && $this->stock > $this->max_stock => 'info',
            default => 'success',
        };
    }

    public function auditLabel(): string
    {
        return 'artículo '.$this->sku;
    }
}
