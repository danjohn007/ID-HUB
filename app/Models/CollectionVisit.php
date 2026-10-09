<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionVisit extends TenantModel
{
    protected $casts = ['visited_at' => 'datetime', 'promise_date' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
