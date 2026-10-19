<?php

namespace App\Services\Messaging;

/**
 * Códigos de error de Twilio más comunes, explicados en español para la bitácora de mensajes.
 * https://www.twilio.com/docs/api/errors
 */
class TwilioErrors
{
    public const MESSAGES = [
        20003 => 'Account SID o Auth token incorrectos.',
        21211 => 'El número del destinatario no es válido.',
        21212 => 'El número remitente no es válido.',
        21408 => 'La cuenta no tiene permiso para enviar a ese país (activa México en Messaging → Geo permissions).',
        21606 => 'El número remitente no puede enviar SMS a ese destino.',
        21608 => 'Cuenta de prueba: solo puede enviar a números verificados en Twilio.',
        21610 => 'El destinatario se dio de baja (respondió STOP).',
        21614 => 'El número del destinatario no es un celular.',
        30003 => 'El teléfono del destinatario no está disponible (apagado o sin señal).',
        30004 => 'El destinatario bloqueó los mensajes.',
        30005 => 'El número del destinatario no existe.',
        30006 => 'El número del destinatario es fijo o no recibe mensajes.',
        30007 => 'El operador filtró el mensaje (posible spam).',
        30008 => 'Error desconocido del operador.',
        63003 => 'El destinatario no tiene WhatsApp.',
        63007 => 'El número remitente no está habilitado para WhatsApp en Twilio.',
        63015 => 'Sandbox de WhatsApp: el destinatario no se ha unido al sandbox.',
        63016 => 'Fuera de la ventana de 24 h: este aviso necesita una plantilla aprobada (Content SID) en Comunicación → Plantillas.',
        63024 => 'El destinatario no es un usuario válido de WhatsApp.',
        63027 => 'WhatsApp no aceptó la plantilla (revisa que esté aprobada y que las variables coincidan).',
        63032 => 'WhatsApp no entregó el mensaje para cuidar la experiencia del usuario (límite de mensajes de marketing).',
        63049 => 'WhatsApp no entregó el mensaje para cuidar la experiencia del usuario (límite de mensajes de marketing).',
    ];

    public static function describe(int|string|null $code): ?string
    {
        return $code ? (self::MESSAGES[(int) $code] ?? null) : null;
    }
}
