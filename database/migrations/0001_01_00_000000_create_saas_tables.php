<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del nivel SaaS: planes de suscripción y empresas WISP (tenants).
 * Compatible con MySQL 5.7: sin defaults en columnas JSON/TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->unsignedInteger('max_clients')->nullable();
            $table->unsignedInteger('max_devices')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('commission_fixed', 8, 2)->default(0);
            $table->json('features')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('legal_name')->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 9)->default('#1A7FF5');
            $table->string('secondary_color', 9)->default('#0B1B3A');
            $table->string('domain')->nullable();
            $table->string('subdomain', 80)->nullable();
            $table->string('currency', 3)->default('MXN');
            $table->string('timezone', 60)->default('America/Mexico_City');
            $table->string('status', 20)->default('active'); // trial, active, suspended, cancelled
            $table->foreignId('saas_plan_id')->nullable()->constrained('saas_plans')->nullOnDelete();
            $table->date('trial_ends_at')->nullable();
            $table->date('subscription_ends_at')->nullable();
            $table->string('suspended_reason')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saas_plan_id')->nullable()->constrained('saas_plans')->nullOnDelete();
            $table->string('folio', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 12, 2);
            $table->decimal('commissions', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('status', 20)->default('pending'); // pending, paid, overdue, cancelled
            $table->dateTime('paid_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_invoices');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('saas_plans');
    }
};
