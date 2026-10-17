<?php

use App\Livewire\Saas\Sales;
use App\Models\DemoMeeting;
use Illuminate\Support\Facades\Route;

// CRM de ventas de ID-Hub (superadmin): prospectos, ficha, agenda de demos. Prefijo /saas y permiso saas.manage en routes/web.php
Route::get('/ventas/prospectos', Sales\Leads::class)->name('saas.sales.leads');
Route::get('/ventas/prospectos/{lead}', Sales\LeadShow::class)->whereNumber('lead')->name('saas.sales.leads.show');
Route::get('/ventas/agenda', Sales\Agenda::class)->name('saas.sales.agenda');

// Invitación de calendario de una demo
Route::get('/ventas/agenda/{meeting}.ics', fn (DemoMeeting $meeting) => response($meeting->ics(), 200, [
    'Content-Type' => 'text/calendar; charset=utf-8',
    'Content-Disposition' => 'attachment; filename="demo-'.$meeting->id.'.ics"',
]))->whereNumber('meeting')->name('saas.sales.meeting.ics');

// Dirección de la primera versión ("Solicitudes de demo")
Route::redirect('/solicitudes-demo', '/saas/ventas/prospectos');
