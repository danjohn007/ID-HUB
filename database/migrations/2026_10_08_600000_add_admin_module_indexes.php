<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices del módulo de administración/SaaS:
 *  - saas_invoices: una factura por WISP y periodo (idempotencia de saas:invoices).
 *  - audit_logs: filtro por WISP + fecha en el visor de auditoría.
 * Compatible con MySQL 5.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->unique(['tenant_id', 'period_start'], 'saas_invoices_tenant_period_unique');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->dropUnique('saas_invoices_tenant_period_unique');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_tenant_created_index');
        });
    }
};
