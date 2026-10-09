<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Client extends TenantModel
{
    use Auditable, HasApiTokens, SoftDeletes;

    protected $hidden = ['pppoe_password', 'portal_password'];

    protected $auditExclude = ['online', 'last_online_at', 'signal_dbm', 'month_usage_gb', 'risk_score', 'risk_level', 'balance', 'credit_balance', 'next_due_date'];

    protected $casts = [
        'pppoe_password' => 'encrypted',
        'installation_date' => 'date',
        'next_due_date' => 'date',
        'suspended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_online_at' => 'datetime',
        'online' => 'boolean',
    ];

    /** Estados que cuentan como cliente con servicio contratado. */
    public const BILLABLE = ['activo', 'suspendido', 'moroso', 'en_revision'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function cpe(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'cpe_device_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'router_device_id');
    }

    public function repeater(): BelongsTo
    {
        return $this->belongsTo(Repeater::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referred_by_client_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function serviceEvents(): HasMany
    {
        return $this->hasMany(ServiceEvent::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CollectionVisit::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_client_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function scopeBillable(Builder $q): Builder
    {
        return $q->whereIn('service_status', self::BILLABLE);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term = trim((string) $term)) {
            return $q;
        }

        return $q->where(function ($w) use ($term) {
            $like = '%'.$term.'%';
            $w->where('code', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', IFNULL(last_name, '')) LIKE ?", [$like])
                ->orWhere('phone', 'like', $like)
                ->orWhere('whatsapp', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('mac_address', 'like', $like)
                ->orWhere('pppoe_user', 'like', $like);
        });
    }

    /** Saldo vencido: cargos pendientes con fecha límite pasada. */
    public function overdueAmount(): float
    {
        return (float) $this->charges()->whereIn('status', ['pending', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->sum(DB::raw('total - paid_amount'));
    }

    public function mapStatus(): string
    {
        return match (true) {
            in_array($this->service_status, ['suspendido', 'cancelado']) => 'offline',
            $this->service_status === 'moroso' => 'warning',
            ! $this->online => 'unknown',
            default => 'online',
        };
    }

    public function auditLabel(): string
    {
        return 'cliente #'.$this->code;
    }
}
