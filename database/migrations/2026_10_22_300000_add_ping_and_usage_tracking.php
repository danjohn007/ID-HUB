<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoreo por ping desde el MikroTik y consumo mensual real:
 *  - repeaters: IP de administración (destino del ping) y última latencia / pérdida medidas.
 *  - clients: último contador de bytes leído del equipo (cola simple o interfaz PPPoE) y mes al que
 *    corresponde month_usage_gb (al cambiar de mes se reinicia el acumulado).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('repeaters', 'management_ip')) {
            Schema::table('repeaters', function (Blueprint $table) {
                $table->string('management_ip', 45)->nullable()->after('ap_device_id');
                $table->decimal('latency_ms', 8, 2)->nullable()->after('down_since');
                $table->decimal('packet_loss', 5, 2)->nullable()->after('latency_ms');
                $table->dateTime('last_ping_at')->nullable()->after('packet_loss');
            });
        }
        if (! Schema::hasColumn('clients', 'usage_counter')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('usage_counter')->nullable()->after('month_usage_gb');
                $table->string('usage_month', 7)->nullable()->after('usage_counter');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('repeaters', 'management_ip')) {
            Schema::table('repeaters', function (Blueprint $table) {
                $table->dropColumn(['management_ip', 'latency_ms', 'packet_loss', 'last_ping_at']);
            });
        }
        if (Schema::hasColumn('clients', 'usage_counter')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn(['usage_counter', 'usage_month']);
            });
        }
    }
};
