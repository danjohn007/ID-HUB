<?php

namespace App\Services\Messaging;

/** Error de Firebase al enviar un push; $invalidToken indica que el dispositivo ya no existe. */
class FcmException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $invalidToken = false)
    {
        parent::__construct($message);
    }
}
