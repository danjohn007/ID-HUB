<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de demo de la página pública: además del correo a ventas quedan registradas
 * para el panel del superadministrador (sin WISP). Compatible con MySQL 5.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 160);
            $table->string('phone', 30)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('clients', 30)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->text('notes')->nullable();
            $table->boolean('mail_sent')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
