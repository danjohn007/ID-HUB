<?php

namespace App\Models;

class MonitoringSample extends TenantModel
{
    public $timestamps = false;

    protected $casts = ['sampled_at' => 'datetime', 'online' => 'boolean'];
}
