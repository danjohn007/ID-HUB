<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base para todos los modelos que pertenecen a un WISP.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}
