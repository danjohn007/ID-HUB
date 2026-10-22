<?php

namespace App\Services\Messaging;

use App\Models\FieldRoute;
use App\Models\InstallationOrder;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Notificaciones push al personal en la app de campo: ticket u orden de instalación asignada
 * y ruta planeada por un supervisor. Se dispara con los eventos de los modelos, así cubre
 * todas las formas de asignar (panel, API, automatizaciones). No avisa a quien se asigna solo.
 *
 * Cada aviso queda en la bitácora de mensajes como "Aviso a personal" (canal push).
 */
class StaffPush
{
    protected static bool $muted = false;

    public static function register(): void
    {
        // Los listeners no devuelven nada: un false detendría a los demás listeners del evento.
        Ticket::created(function (Ticket $t) {
            static::ticketAssigned($t);
        });
        Ticket::updated(function (Ticket $t) {
            $t->wasChanged('technician_id') && static::ticketAssigned($t);
        });
        InstallationOrder::created(function (InstallationOrder $o) {
            static::installationAssigned($o);
        });
        InstallationOrder::updated(function (InstallationOrder $o) {
            $o->wasChanged('technician_id') && static::installationAssigned($o);
        });
    }

    /** Ejecuta sin avisos por asignación (p. ej. al guardar una ruta, que manda un solo aviso). */
    public static function quietly(callable $callback): mixed
    {
        $previous = static::$muted;
        static::$muted = true;
        try {
            return $callback();
        } finally {
            static::$muted = $previous;
        }
    }

    protected static function ticketAssigned(Ticket $ticket): bool
    {
        if (! $ticket->technician_id || ! $ticket->isOpen()) {
            return false;
        }
        $body = trim($ticket->folio.' · '.$ticket->subject.($ticket->client ? ' · '.$ticket->client->full_name : ''));

        return static::assigned($ticket, $ticket->technician_id, 'Nuevo ticket asignado', $body, ['type' => 'ticket', 'id' => $ticket->id]) > 0;
    }

    protected static function installationAssigned(InstallationOrder $order): bool
    {
        if (! $order->technician_id || in_array($order->status, ['instalada', 'activada', 'cancelada'], true)) {
            return false;
        }
        $body = $order->folio.' · '.$order->customerName().($order->scheduled_at ? ' · '.$order->scheduled_at->translatedFormat('d M H:i') : '');

        return static::assigned($order, $order->technician_id, 'Nueva instalación asignada', $body, ['type' => 'installation_order', 'id' => $order->id]) > 0;
    }

    protected static function assigned(Model $subject, int $userId, string $title, string $body, array $data): int
    {
        if (static::$muted || auth()->id() === $userId) {
            return 0;
        }
        $user = User::query()->withoutGlobalScopes()->whereKey($userId)->where('active', true)->first();

        return $user ? app(static::class)->toUser($user, $title, $body, $data, (int) $subject->tenant_id) : 0;
    }

    /** Aviso de ruta planeada por otra persona (un solo push por ruta, no uno por parada). */
    public function routePlanned(FieldRoute $route): int
    {
        if (auth()->id() === $route->user_id || ! ($user = User::query()->withoutGlobalScopes()->find($route->user_id))) {
            return 0;
        }
        $pending = $route->stops()->whereNotIn('status', ['arrived', 'done'])->count();
        $day = $route->date->isToday() ? 'de hoy' : 'del '.$route->date->translatedFormat('d \d\e F');

        return $this->toUser($user, 'Tu ruta '.$day.' está lista', $pending.' '.($pending === 1 ? 'parada pendiente' : 'paradas pendientes').'. Ábrela para ver el orden de visitas.',
            ['type' => 'route', 'route_type' => $route->type, 'date' => $route->date->toDateString()], (int) $route->tenant_id);
    }

    /** Encola un push a cada dispositivo del usuario. Devuelve cuántos se encolaron. */
    public function toUser(User $user, string $title, string $body, array $data = [], ?int $tenantId = null): int
    {
        $tenant = Tenant::find($tenantId ?? $user->tenant_id);
        $tokens = PushToken::withoutGlobalScopes()->where('user_id', $user->id)->pluck('token');
        if (! $tenant || $tokens->isEmpty()) {
            return 0;
        }
        $data = array_map(fn ($v) => (string) $v, $data);
        foreach ($tokens as $token) {
            app(MessageService::class)->queue($tenant, 'push', $token, $body, $title, null, 'staff', null, ['data' => $data, 'user' => $user->name]);
        }

        return $tokens->count();
    }
}
