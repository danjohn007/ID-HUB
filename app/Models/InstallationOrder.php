<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InstallationOrder extends TenantModel
{
    use Auditable;

    protected $casts = [
        'quote_items' => 'array',
        'checklist' => 'array',
        'quote_accepted_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    /** Flujo: cotizacion → aceptada → programada → en_proceso → instalada → activada */
    public const FLOW = ['cotizacion', 'aceptada', 'programada', 'en_proceso', 'instalada', 'activada'];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function customerName(): string
    {
        return $this->client?->full_name ?? $this->prospect?->name ?? '—';
    }

    public function checklistProgress(): int
    {
        $items = config('wisp.installation_checklist');
        $done = collect($this->checklist ?? [])->filter()->count();

        return $items ? (int) round($done / count($items) * 100) : 0;
    }

    public function auditLabel(): string
    {
        return 'orden de instalación '.$this->folio;
    }
}
