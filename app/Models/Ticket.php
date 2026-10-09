<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Ticket extends TenantModel
{
    use Auditable;

    protected $auditExclude = ['sla_warned'];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'sla_breached' => 'boolean',
        'sla_warned' => 'boolean',
        'arrived_at' => 'datetime',
        'departed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'on_way_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public const OPEN_STATUSES = ['abierto', 'asignado', 'en_proceso', 'en_espera'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function installationOrder(): BelongsTo
    {
        return $this->belongsTo(InstallationOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->latest();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Estado del SLA: ok, risk, breached, done */
    public function slaState(): string
    {
        if (! $this->isOpen()) {
            return $this->sla_breached ? 'breached' : 'done';
        }
        if (! $this->sla_due_at) {
            return 'ok';
        }
        if ($this->sla_due_at->isPast()) {
            return 'breached';
        }
        $total = max(1, $this->created_at->diffInMinutes($this->sla_due_at));
        $elapsed = $this->created_at->diffInMinutes(now());

        return $elapsed / $total >= 0.75 ? 'risk' : 'ok';
    }

    public function addComment(string $body, string $type = 'comment', bool $internal = false, array $meta = []): TicketComment
    {
        return $this->comments()->create([
            'user_id' => auth()->id(),
            'type' => $type,
            'body' => $body,
            'internal' => $internal,
            'meta' => $meta ?: null,
        ]);
    }

    public function auditLabel(): string
    {
        return 'ticket '.$this->folio;
    }
}
