<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la plantilla de WhatsApp en Meta (APPROVED, PENDING, REJECTED...) y motivo de rechazo.
 * Se actualiza al enviarla a revisión, con "Actualizar estados" y por el webhook de Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('provider_status', 20)->nullable()->after('provider_template');
            $table->string('provider_note')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'provider_note']);
        });
    }
};
