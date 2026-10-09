<?php

use App\Livewire\Alerts;
use App\Livewire\Automations;
use App\Livewire\Communications;
use Illuminate\Support\Facades\Route;

// Comunicación: bitácora de mensajes, campañas masivas y plantillas (secciones 6.2, 34, 35, 36)
Route::middleware('can:communications.manage')->group(function () {
    Route::get('/mensajes', Communications\Messages::class)->name('communications.messages');
    Route::get('/campanas', Communications\Campaigns\Index::class)->name('communications.campaigns');
    Route::get('/campanas/nueva', Communications\Campaigns\Edit::class)->name('communications.campaigns.create');
    Route::get('/campanas/{campaign}', Communications\Campaigns\Show::class)->name('communications.campaigns.show');
    Route::get('/campanas/{campaign}/editar', Communications\Campaigns\Edit::class)->name('communications.campaigns.edit');
    Route::get('/plantillas', Communications\Templates::class)->name('communications.templates');
});

// Automatizaciones  Evento → Condición → Acción (sección 46)
Route::middleware('can:automations.manage')->group(function () {
    Route::get('/automatizaciones', Automations\Index::class)->name('automations.index');
    Route::get('/automatizaciones/nueva', Automations\Edit::class)->name('automations.create');
    Route::get('/automatizaciones/{rule}/editar', Automations\Edit::class)->name('automations.edit');
});

// Alertas inteligentes (sección 45)
Route::get('/alertas', Alerts\Index::class)->middleware('can:alerts.view')->name('alerts.index');
