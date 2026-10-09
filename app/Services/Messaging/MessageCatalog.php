<?php

namespace App\Services\Messaging;

use App\Models\Message;

/**
 * Etiquetas e íconos de la bitácora de mensajes (eventos de plantilla + orígenes especiales).
 */
class MessageCatalog
{
    public const EXTRA_EVENTS = [
        'campaign' => 'Campaña',
        'manual' => 'Mensaje manual',
        'staff' => 'Aviso a personal',
        'test' => 'Envío de prueba',
    ];

    public const CHANNEL_ICONS = [
        'whatsapp' => 'chat-bubble-oval-left-ellipsis',
        'sms' => 'device-phone-mobile',
        'email' => 'envelope',
        'push' => 'bell',
    ];

    public const CHANNEL_COLORS = [
        'whatsapp' => 'success',
        'sms' => 'brand',
        'email' => 'purple',
        'push' => 'warning',
    ];

    public static function events(): array
    {
        return config('wisp.message_events') + self::EXTRA_EVENTS;
    }

    public static function eventLabel(?string $event): string
    {
        return $event ? (self::events()[$event] ?? ucfirst(str_replace('_', ' ', $event))) : '—';
    }

    public static function statusLabel(?string $status): string
    {
        return Message::STATUS[$status][0] ?? (string) $status;
    }

    public static function statusColor(?string $status): string
    {
        return Message::STATUS[$status][1] ?? 'slate';
    }

    /** Ejemplo de enlace de pago para vistas previas (no genera enlaces reales). */
    public static function sampleLink(): string
    {
        return rtrim(config('app.url'), '/').'/pagar/AB12CD34';
    }
}
