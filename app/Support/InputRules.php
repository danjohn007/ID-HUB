<?php

namespace App\Support;

/**
 * Reglas de formato reutilizables para los formularios. Coinciden con los filtros de captura
 * de resources/js/app.js (data-filter / <x-ui.input filter="...">), que bloquean los caracteres
 * no permitidos al escribir; estas reglas los rechazan en el servidor.
 *
 *   'form.first_name' => ['required', 'string', 'max:80', InputRules::NAME],
 *
 * Ninguna contiene "|", así que también pueden concatenarse en reglas de texto.
 */
final class InputRules
{
    /** Nombres de personas: letras (con acentos), espacios, punto, apóstrofo y guion. */
    public const NAME = 'regex:/^[\pL\s.\'\-]+$/u';

    /** Texto general (razón social, títulos, direcciones): sin < > { } [ ] \ | ^ ~ ` $ * =. */
    public const TEXT = 'regex:/^[\pL\pN\s.,;:_#&()\/\'"!¡?¿°%@+\-]+$/u';

    /** Letras, números y espacios. */
    public const ALNUM = 'regex:/^[\pL\pN\s]+$/u';

    /** Teléfono: dígitos, espacios, paréntesis, guiones y "+" inicial; 7 a 20 caracteres. */
    public const PHONE = 'regex:/^\+?[0-9\s()\-]{7,20}$/';

    /** Solo dígitos. */
    public const DIGITS = 'regex:/^\d+$/';

    /** Códigos, SKU, folios: letras sin acentos, números, guion y guion bajo. */
    public const CODE = 'regex:/^[A-Za-z0-9_\-]+$/';

    /** Usuarios técnicos (PPPoE, API): letras, números, punto, guion, guion bajo y @. */
    public const USERNAME = 'regex:/^[A-Za-z0-9._@\-]+$/';

    /** RFC de persona física o moral. */
    public const RFC = 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u';

    /** CURP (18 caracteres). */
    public const CURP = 'regex:/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/';

    /** Color hexadecimal #RRGGBB. */
    public const COLOR = 'regex:/^#[0-9A-Fa-f]{6}$/';

    /** Host o IP (sin protocolo). */
    public const HOST = 'regex:/^[A-Za-z0-9.\-:]+$/';
}
