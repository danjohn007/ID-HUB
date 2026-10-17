<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada del historial de un prospecto del CRM de ventas. */
class DemoActivity extends Model
{
    protected $guarded = ['id'];

    /** Tipos que se capturan a mano (el resto los genera el sistema). */
    public const TYPES = ['note' => 'Nota', 'call' => 'Llamada', 'whatsapp' => 'WhatsApp', 'email' => 'Correo'];

    public const ICONS = [
        'note' => 'pencil-square', 'call' => 'phone', 'whatsapp' => 'chat-bubble-oval-left', 'email' => 'envelope',
        'meeting' => 'calendar-days', 'stage' => 'arrow-right-circle', 'system' => 'sparkles',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class, 'demo_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
