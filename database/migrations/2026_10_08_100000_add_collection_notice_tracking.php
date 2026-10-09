<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza automática: control de avisos enviados por cargo (recordatorio, vencimiento,
 * aviso de suspensión) y del disparo diario de "pago vencido" por cliente, para que
 * las tareas programadas sean idempotentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dateTime('reminder_sent_at')->nullable()->after('paid_at');
            $table->dateTime('due_notice_sent_at')->nullable()->after('reminder_sent_at');
            $table->dateTime('suspension_warning_sent_at')->nullable()->after('due_notice_sent_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->date('overdue_fired_on')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['gateway', 'external_id'], 'payments_gateway_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_gateway_external_idx');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('overdue_fired_on');
        });
        Schema::table('charges', function (Blueprint $table) {
            $table->dropColumn(['reminder_sent_at', 'due_notice_sent_at', 'suspension_warning_sent_at']);
        });
    }
};
