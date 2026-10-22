<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App de campo sin conexión: las acciones se reintentan desde la bandeja de salida del teléfono.
 * - payments / collection_visits: clave de idempotencia única por WISP (un reintento no duplica el cobro).
 * - api_idempotency_keys: respuesta guardada de otras acciones de campo (llegada, estado, comentarios, fotos).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'collection_visits'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->string('idempotency_key', 64)->nullable()->after('id');
                $t->unique(['tenant_id', 'idempotency_key'], $table.'_idempotency_unique');
            });
        }

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 64);
            $table->string('method', 8);
            $table->string('path', 190);
            $table->unsignedSmallInteger('status')->nullable(); // null = en proceso
            $table->longText('response')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
        foreach (['payments', 'collection_visits'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_idempotency_unique');
                $t->dropColumn('idempotency_key');
            });
        }
    }
};
