<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prospect extends TenantModel
{
    use Auditable;

    protected $casts = [
        'has_coverage' => 'boolean',
        'contacted_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function desiredPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'desired_plan_id');
    }

    public function nearestRepeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class, 'nearest_repeater_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function installationOrders(): HasMany
    {
        return $this->hasMany(InstallationOrder::class);
    }

    public function auditLabel(): string
    {
        return 'prospecto '.$this->name;
    }
}
