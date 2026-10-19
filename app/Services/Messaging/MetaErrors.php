<?php

namespace App\Services\Messaging;

/**
 * Códigos de error de WhatsApp Cloud API (Meta) más comunes, explicados en español.
 * https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
 */
class MetaErrors
{
    public const MESSAGES = [
        0 => 'No se pudo autenticar con Meta: revisa el token de acceso.',
        3 => 'La app de Meta no tiene permiso para WhatsApp (whatsapp_business_messaging).',
        10 => 'El token no tiene permiso para este número de WhatsApp.',
        100 => 'Meta rechazó un dato del envío: revisa el Phone number ID y el número del destinatario.',
        190 => 'El token de acceso de Meta venció o no es válido: genera un token permanente (usuario del sistema).',
        368 => 'Meta bloqueó temporalmente la cuenta por incumplir sus políticas.',
        130429 => 'Se alcanzó el límite de envíos por segundo de Meta; se reintentará más tarde.',
        131000 => 'Error desconocido de Meta.',
        131005 => 'El token no tiene permiso para enviar mensajes.',
        131008 => 'Falta un dato obligatorio en el envío.',
        131009 => 'Un valor del mensaje no es válido (revisa las variables de la plantilla).',
        131021 => 'El destinatario es el mismo número que envía.',
        131026 => 'El mensaje no se pudo entregar: el número no tiene WhatsApp o usa una versión antigua.',
        131030 => 'Número de prueba de Meta: agrega este celular a la lista de destinatarios permitidos.',
        131031 => 'La cuenta de WhatsApp Business está bloqueada.',
        131042 => 'Problema con el método de pago de la cuenta de WhatsApp Business en Meta.',
        131047 => 'Fuera de la ventana de 24 h: este aviso necesita una plantilla aprobada en Comunicación → Plantillas.',
        131049 => 'Meta no entregó el mensaje para cuidar la experiencia del usuario (demasiados mensajes de empresas).',
        131050 => 'El cliente pidió dejar de recibir mensajes de marketing.',
        131051 => 'Tipo de mensaje no admitido.',
        131056 => 'Demasiados mensajes seguidos al mismo cliente; espera un momento.',
        132000 => 'El número de variables no coincide con la plantilla aprobada.',
        132001 => 'La plantilla no existe en Meta o no está aprobada en ese idioma.',
        132005 => 'El texto de la plantilla con las variables es demasiado largo.',
        132007 => 'El contenido de la plantilla no cumple las políticas de WhatsApp.',
        132012 => 'El formato de las variables no coincide con la plantilla aprobada.',
        132015 => 'La plantilla está pausada por baja calidad.',
        132016 => 'La plantilla está deshabilitada por baja calidad.',
        133010 => 'El número de WhatsApp de la empresa no está registrado en Cloud API.',
    ];

    public static function describe(int|string|null $code): ?string
    {
        return $code !== null && $code !== '' ? (self::MESSAGES[(int) $code] ?? null) : null;
    }
}
