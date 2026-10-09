<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Crecimiento: promoción aplicada a la orden de instalación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('installation_orders', 'promotion_id')) {
            Schema::table('installation_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('promotion_id')->nullable()->after('repeater_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('installation_orders', 'promotion_id')) {
            Schema::table('installation_orders', function (Blueprint $table) {
                $table->dropIndex(['promotion_id']);
                $table->dropColumn('promotion_id');
            });
        }
    }
};
