<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp por plantillas aprobadas (Twilio Content API) y estado de entrega por webhook:
 *  - notification_templates.provider_template: Content SID (HX...) de la plantilla aprobada.
 *  - messages.meta: datos de envío al proveedor (content_sid, variables {{1}}, {{2}}...).
 *  - índice en messages.provider_id para resolver los callbacks de estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('provider_template', 80)->nullable()->after('body');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('body');
            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['provider_id']);
            $table->dropColumn('meta');
        });

        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('provider_template');
        });
    }
};
