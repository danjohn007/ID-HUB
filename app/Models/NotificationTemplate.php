<?php

namespace App\Models;

class NotificationTemplate extends TenantModel
{
    protected $casts = ['active' => 'boolean'];

    /** Variables disponibles en las plantillas. */
    public const VARIABLES = [
        '{nombre}', '{cliente}', '{codigo}', '{plan}', '{monto}', '{saldo}', '{fecha_limite}',
        '{empresa}', '{telefono_empresa}', '{link_pago}', '{folio}', '{tecnico}', '{fecha}', '{zona}', '{descripcion}',
    ];
}
