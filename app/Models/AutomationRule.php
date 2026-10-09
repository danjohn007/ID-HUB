<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regla Evento → Condición → Acción.
 * conditions: [{field, operator, value}]  (operadores: =, !=, >, >=, <, <=, in, contains)
 * actions:    [{type, params:{...}}]       (tipos en config('wisp.automation_actions'))
 */
class AutomationRule extends TenantModel
{
    use Auditable;

    protected $auditExclude = ['run_count', 'last_run_at'];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'active' => 'boolean',
        'system' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->latest();
    }

    public function auditLabel(): string
    {
        return 'automatización '.$this->name;
    }
}
