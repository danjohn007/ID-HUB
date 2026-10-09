<?php

use Illuminate\Support\Facades\Route;

// API v1 para integraciones y futuras apps móviles (ver routes/modules/api.php)
foreach (glob(__DIR__.'/api/*.php') as $file) {
    require $file;
}
