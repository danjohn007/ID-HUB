<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptiveAdStat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['date' => 'date'];
}
