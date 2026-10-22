<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasPlan extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['features' => 'array', 'active' => 'boolean'];

    public const FEATURES = [
        'dashboard' => 'Dashboard',
        'cobranza' => 'Cobranza',
        'app_tecnico' => 'App técnico',
        'app_cliente' => 'App cliente',
        'whatsapp' => 'WhatsApp',
        'mapas' => 'Mapas',
        'multiusuario' => 'Multiusuario',
        'bi' => 'BI avanzado',
        'api' => 'API',
        'white_label' => 'White Label',
        'portal_cautivo' => 'Portal cautivo',
        'automatizaciones' => 'Automatizaciones',
    ];

    /** Permiso del panel → función del plan que lo habilita. */
    public const ABILITY_FEATURES = [
        'bi.view' => 'bi',
        'map.view' => 'mapas',
        'automations.manage' => 'automatizaciones',
        'captive.manage' => 'portal_cautivo',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
