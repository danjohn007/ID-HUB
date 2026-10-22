<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso automático de pago pendiente (sección 6.2): se envía una vez por cargo,
 * N días después de la fecha límite (billing.pending_notice_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dateTime('pending_notice_sent_at')->nullable()->after('due_notice_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn('pending_notice_sent_at');
        });
    }
};
