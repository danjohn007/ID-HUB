<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora ligera de eventos de conexión detectados por el monitoreo
 * (desconexiones, cambios de MAC/IP). Sirve para detectar desconexiones
 * frecuentes y problemas recurrentes (secciones 14 y 15). Se depura a 30 días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->unsignedBigInteger('repeater_id')->nullable()->index();
            $table->string('type', 20); // disconnect, connect, mac_change
            $table->string('detail', 190)->nullable();
            $table->dateTime('occurred_at')->index();
            $table->index(['tenant_id', 'client_id', 'occurred_at'], 'conn_ev_client_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_events');
    }
};
