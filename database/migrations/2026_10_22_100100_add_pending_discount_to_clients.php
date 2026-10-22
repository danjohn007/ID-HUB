<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento por aplicar en la próxima mensualidad (recompensas de referidos tipo
 * "Descuento" o "Promoción personalizada", sección 29).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('pending_discount', 12, 2)->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('pending_discount');
        });
    }
};
