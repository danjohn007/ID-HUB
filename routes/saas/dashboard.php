<?php

use App\Livewire\Saas;
use Illuminate\Support\Facades\Route;

// Panel del superadministrador (secciones 3, 48 y 49). Prefijo /saas y permiso saas.manage en routes/web.php
Route::get('/', Saas\Dashboard::class)->name('saas.dashboard');
Route::get('/planes', Saas\Plans::class)->name('saas.plans');
Route::get('/comisiones', Saas\Commissions::class)->name('saas.commissions');
Route::get('/sistema', Saas\System::class)->name('saas.system');
