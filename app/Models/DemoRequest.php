<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * Prospecto del CRM de ventas de ID-Hub (nivel plataforma, sin WISP). Nace de la solicitud de demo
 * de la página pública o se captura a mano; la etapa vive en la columna "status".
 */
class DemoRequest extends Model
{
    protected $guarded = ['id'];

    /** Mismos valores por omisión que la tabla, para que el modelo recién creado los tenga en memoria. */
    protected $attributes = ['status' => 'new', 'source' => 'landing', 'max_stage' => 0];

    protected $casts = [
        'mail_sent' => 'boolean', 'uses_mikrotik' => 'boolean', 'estimated_mrr' => 'decimal:2',
        'next_action_at' => 'datetime', 'contacted_at' => 'datetime', 'stage_changed_at' => 'datetime',
        'won_at' => 'datetime', 'lost_at' => 'datetime',
    ];

    /** Etapas en orden del embudo. */
    public const STAGES = [
        'new' => 'Nuevo', 'contacted' => 'Contactado', 'demo_scheduled' => 'Demo agendada', 'demo_done' => 'Demo realizada',
        'trial' => 'En prueba', 'proposal' => 'Propuesta', 'won' => 'Ganado', 'lost' => 'Perdido',
    ];

    public const STAGE_COLORS = [
        'new' => 'brand', 'contacted' => 'teal', 'demo_scheduled' => 'purple', 'demo_done' => 'navy',
        'trial' => 'warning', 'proposal' => 'info', 'won' => 'success', 'lost' => 'danger',
    ];

    public const CLOSED_STAGES = ['won', 'lost'];

    public const SOURCES = [
        'landing' => 'Página web', 'whatsapp' => 'WhatsApp', 'call' => 'Llamada', 'referral' => 'Referido',
        'event' => 'Evento', 'social' => 'Redes sociales', 'other' => 'Otro',
    ];

    public const LOST_REASONS = [
        'price' => 'Precio', 'competitor' => 'Se quedó con otro sistema', 'no_response' => 'No respondió',
        'not_ready' => 'Aún no está listo', 'no_fit' => 'No es para su operación', 'other' => 'Otro',
    ];

    /** Rangos del formulario público → clientes estimados (para sugerir plan). */
    public const CLIENT_RANGES = ['Menos de 100' => 60, '100 a 500' => 300, '500 a 2,000' => 1000, 'Más de 2,000' => 3000, 'Aún no opero' => 0];

    public const SOFTWARE = ['Ninguno', 'Excel / hojas de cálculo', 'Wisphub', 'Mikrowisp', 'Splynx', 'Sistema propio', 'Otro'];

    public function activities(): HasMany
    {
        return $this->hasMany(DemoActivity::class)->latest()->latest('id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(DemoMeeting::class)->orderByDesc('starts_at');
    }

    public function saasPlan(): BelongsTo
    {
        return $this->belongsTo(SaasPlan::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED_STAGES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->next_action_at && $this->next_action_at->isPast();
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->status] ?? $this->status;
    }

    public function clientsEstimate(): ?int
    {
        return $this->clients_count ?? (self::CLIENT_RANGES[$this->clients] ?? null);
    }

    /** Plan propuesto, o el más chico cuyo límite de clientes alcanza para su operación. */
    public function planOrSuggested(): ?SaasPlan
    {
        return $this->saasPlan ?? static::suggestedPlan($this->clientsEstimate());
    }

    public static function suggestedPlan(?int $clients): ?SaasPlan
    {
        if ($clients === null) {
            return null;
        }
        $plans = once(fn () => SaasPlan::where('active', true)->orderBy('sort')->orderBy('price_monthly')->get()); // una consulta por petición

        return $plans->first(fn ($p) => $p->max_clients === null || $p->max_clients >= $clients) ?? $plans->last();
    }

    /** Ingreso mensual: plan del WISP ya creado, el estimado capturado o el precio del plan sugerido. */
    public function mrr(): float
    {
        return (float) ($this->tenant?->saasPlan?->price_monthly ?? $this->estimated_mrr ?? $this->planOrSuggested()?->price_monthly ?? 0);
    }

    /** Registra una actividad en el historial (user null = sistema / el propio prospecto). */
    public function log(string $type, string $body, ?int $userId = null): DemoActivity
    {
        return $this->activities()->create(['type' => $type, 'body' => $body, 'user_id' => $userId ?? auth()->id()]);
    }

    /** Cambia de etapa dejando constancia en el historial y las fechas clave del embudo. */
    public function changeStage(string $stage, array $extra = [], ?string $note = null): void
    {
        abort_unless(array_key_exists($stage, self::STAGES), 422);
        $from = $this->status;
        $order = array_flip(array_keys(self::STAGES));
        $this->fill($extra + [
            'status' => $stage,
            'stage_changed_at' => now(),
            'max_stage' => $stage === 'lost' ? $this->max_stage : max((int) $this->max_stage, $order[$stage]),
            'contacted_at' => $this->contacted_at ?? (in_array($stage, ['new', 'lost'], true) ? null : now()),
            'won_at' => $stage === 'won' ? ($this->won_at ?? now()) : null,
            'lost_at' => $stage === 'lost' ? now() : null,
            'lost_reason' => $stage === 'lost' ? ($extra['lost_reason'] ?? $this->lost_reason) : null,
        ])->save();

        if ($from !== $stage) {
            $text = 'Etapa: '.(self::STAGES[$from] ?? $from).' → '.self::STAGES[$stage];
            if ($stage === 'lost' && $this->lost_reason) {
                $text .= ' ('.(self::LOST_REASONS[$this->lost_reason] ?? $this->lost_reason).')';
            }
            $this->log('stage', $text.($note ? ". {$note}" : ''));
        }
    }

    /** Prospectos nuevos sin atender (insignia del menú). Cero si la migración aún no se ha aplicado. */
    public static function pendingCount(): int
    {
        try {
            return static::where('status', 'new')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Enlace de WhatsApp: a 10 dígitos se les antepone la lada de México. */
    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone);
        if (strlen($digits) < 10) {
            return null;
        }

        return 'https://wa.me/'.(strlen($digits) === 10 ? '52'.$digits : $digits);
    }
}
