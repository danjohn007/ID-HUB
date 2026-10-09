<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CaptiveAd extends TenantModel
{
    protected $casts = ['starts_at' => 'date', 'ends_at' => 'date', 'active' => 'boolean'];

    public function stats(): HasMany
    {
        return $this->hasMany(CaptiveAdStat::class);
    }

    public function scopeCurrent(Builder $q): Builder
    {
        $today = now()->toDateString();

        return $q->where('active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $today));
    }

    public function ctr(): float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 2) : 0;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
