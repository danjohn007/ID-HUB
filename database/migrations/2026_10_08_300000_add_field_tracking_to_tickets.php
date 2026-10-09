<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operación de campo: "técnico en camino" y datos de la firma del cliente en tickets;
 * notas por parada de ruta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'on_way_at')) {
                $table->dateTime('on_way_at')->nullable()->after('sla_warned');
            }
            if (! Schema::hasColumn('tickets', 'signed_by_name')) {
                $table->string('signed_by_name')->nullable()->after('signature_path');
            }
            if (! Schema::hasColumn('tickets', 'signed_at')) {
                $table->dateTime('signed_at')->nullable()->after('signed_by_name');
            }
        });

        Schema::table('route_stops', function (Blueprint $table) {
            if (! Schema::hasColumn('route_stops', 'notes')) {
                $table->string('notes')->nullable()->after('departed_at');
            }
            if (! Schema::hasColumn('route_stops', 'distance_km')) {
                $table->decimal('distance_km', 8, 2)->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['on_way_at', 'signed_by_name', 'signed_at']);
        });
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn(['notes', 'distance_km']);
        });
    }
};
