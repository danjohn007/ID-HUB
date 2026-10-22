<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SLA por tipo de incidencia (sección 22): una política puede ser de una prioridad
 * (category = null) o de una categoría de ticket (priority = '*').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('priority');
            // El índice nuevo empieza por tenant_id, así la llave foránea lo sigue usando.
            $table->unique(['tenant_id', 'priority', 'category']);
        });
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'priority']);
        });
    }

    public function down(): void
    {
        DB::table('sla_policies')->whereNotNull('category')->delete();
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->unique(['tenant_id', 'priority']);
        });
        Schema::table('sla_policies', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'priority', 'category']);
            $table->dropColumn('category');
        });
    }
};
