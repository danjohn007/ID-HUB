<?php

namespace App\Services\Messaging;

/**
 * Textos por defecto cuando el WISP no ha personalizado una plantilla.
 */
class DefaultTemplates
{
    public const BODIES = [
        'reminder' => 'Hola {nombre}, te recordamos que tu servicio de Internet ({plan}) vence el {fecha_limite}. Monto: {monto}. Paga aquí: {link_pago}',
        'due' => 'Hola {nombre}, tu servicio vence hoy. Monto a pagar: {monto}. Paga en línea: {link_pago}',
        'pending' => 'Hola {nombre}, tu pago está pendiente. Saldo: {saldo}. Evita la suspensión pagando aquí: {link_pago}',
        'suspension_warning' => 'Hola {nombre}, tu servicio será suspendido por falta de pago. Saldo pendiente: {saldo}. Paga aquí: {link_pago}',
        'suspended' => 'Hola {nombre}, tu servicio ha sido suspendido por falta de pago. Saldo: {saldo}. Al pagar se reactiva automáticamente: {link_pago}',
        'payment_received' => 'Hola {nombre}, hemos recibido tu pago de {monto} correctamente. Folio: {folio}. Comprobante: {link_comprobante} ¡Gracias!',
        'reconnected' => 'Hola {nombre}, tu servicio ha sido reactivado. ¡Gracias por tu pago!',
        'ticket_created' => 'Hola {nombre}, recibimos tu reporte #{folio}. Te mantendremos informado.',
        'ticket_assigned' => 'Hola {nombre}, tu reporte #{folio} ha sido asignado al técnico {tecnico}.',
        'ticket_updated' => 'Hola {nombre}, tu reporte #{folio} fue actualizado: {descripcion}',
        'technician_on_way' => 'Hola {nombre}, el técnico {tecnico} va en camino a tu domicilio.',
        'maintenance' => 'Aviso: realizaremos mantenimiento programado el {fecha} en {zona}. {descripcion}',
        'outage' => 'Aviso: detectamos una falla en tu zona ({zona}). Nuestro equipo ya está trabajando en ella.',
        'promotion' => '{descripcion}',
        'welcome' => '¡Bienvenido a {empresa}, {nombre}! Tu número de cliente es {codigo}. Plan: {plan}.',
        'payment_link' => 'Hola {nombre}, este es tu enlace para pagar {monto}: {link_pago}',
    ];

    public const SUBJECTS = [
        'reminder' => 'Recordatorio de pago',
        'due' => 'Tu servicio vence hoy',
        'pending' => 'Pago pendiente',
        'suspension_warning' => 'Aviso de suspensión',
        'suspended' => 'Servicio suspendido',
        'payment_received' => 'Pago recibido',
        'reconnected' => 'Servicio reactivado',
        'maintenance' => 'Mantenimiento programado',
        'payment_link' => 'Enlace de pago',
    ];

    public static function body(string $event): string
    {
        return self::BODIES[$event] ?? '{descripcion}';
    }

    public static function subject(string $event): string
    {
        return self::SUBJECTS[$event] ?? config("wisp.message_events.$event", 'Aviso');
    }
}
