<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuarios de la plataforma. No usa el scope de tenant automáticamente (el login
 * es global); usar User::forTenant() / scopeStaff() en los listados.
 */
class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        // Cambio de contraseña o usuario desactivado: se cierran las sesiones de las apps móviles.
        static::updated(function (User $user) {
            if ($user->wasChanged('password') || ($user->wasChanged('active') && ! $user->active)) {
                $user->tokens()->delete();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_location_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'on_call' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'technician_id');
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function roleLabel(): string
    {
        return config("wisp.roles.{$this->role}", $this->role);
    }

    /** Permisos del rol según config/permissions.php */
    public function hasAbility(string $ability): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }
        $abilities = config("permissions.roles.{$this->role}", []);

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /** Usuarios del WISP activo. */
    public function scopeForTenant(Builder $q, ?int $tenantId = null): Builder
    {
        return $q->where('tenant_id', $tenantId ?? tenant()?->id);
    }

    public function scopeRole(Builder $q, string|array $roles): Builder
    {
        return $q->whereIn('role', (array) $roles);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function homeRoute(): string
    {
        return match (true) {
            $this->isSuperadmin() && ! session('acting_tenant_id') => 'saas.dashboard',
            $this->role === 'tecnico' => 'field.today',
            $this->role === 'cobrador' => 'field.collection',
            default => 'dashboard',
        };
    }

    public function auditLabel(): string
    {
        return 'usuario '.$this->name;
    }
}
