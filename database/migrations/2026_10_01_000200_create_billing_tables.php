<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranza: cargos, pagos, aplicación de pagos, enlaces de pago, pasarelas,
 * cortes de caja, visitas de cobradores y documentos generados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('folio', 30)->nullable();
            $table->string('type', 20)->default('mensualidad'); // mensualidad, instalacion, recargo, reconexion, equipo, otro
            $table->string('description');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->date('due_date')->index();
            $table->string('status', 15)->default('pending')->index(); // pending, partial, paid, cancelled
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'client_id', 'status']);
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // mercadopago, stripe, paypal, oxxo, custom
            $table->boolean('enabled')->default(false);
            $table->boolean('sandbox')->default(true);
            $table->text('credentials')->nullable(); // JSON cifrado
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->decimal('fee_fixed', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('cash_cuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('folio', 30);
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('expected_amount', 12, 2)->default(0);
            $table->decimal('delivered_amount', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->string('status', 15)->default('open'); // open, delivered, reconciled
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('concept')->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('checkout_url', 500)->nullable();
            $table->string('status', 15)->default('pending'); // pending, paid, expired, cancelled
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('folio', 30)->index();
            $table->string('kind', 10)->default('payment'); // payment, credit (descuento/bonificación)
            $table->string('method', 20); // efectivo, transferencia, deposito, tarjeta, mercadopago, stripe, paypal, oxxo, saldo, descuento, bonificacion, referido
            $table->decimal('amount', 12, 2);
            $table->decimal('gateway_fee', 12, 2)->default(0);
            $table->decimal('platform_fee', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->string('reference')->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('status', 15)->default('completed')->index(); // pending, completed, failed, refunded, cancelled
            $table->dateTime('paid_at')->index();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_cut_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evidence_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'paid_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('collection_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collector_id')->constrained('users')->cascadeOnDelete();
            $table->string('result', 20); // pago, no_localizado, promesa, rechazo, cerrado
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->date('promise_date')->nullable();
            $table->decimal('promise_amount', 12, 2)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->string('evidence_path')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->dateTime('visited_at');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20); // contrato, orden, recibo, comprobante, evidencia
            $table->string('number', 40)->nullable();
            $table->string('documentable_type', 60)->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->string('path')->nullable();
            $table->string('signature_path')->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['documents', 'collection_visits', 'payment_allocations', 'payments', 'payment_links', 'cash_cuts', 'payment_gateways', 'charges'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
