<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operación: prospectos, órdenes de instalación, tickets + SLA, evidencias,
 * rutas de campo e inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('desired_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('source', 20)->default('otro'); // facebook, referido, volante, web, portal, whatsapp, llamada, otro
            $table->string('status', 25)->default('nuevo')->index(); // nuevo, contactado, viable, sin_cobertura, instalacion_pendiente, convertido, descartado
            $table->foreignId('nearest_repeater_id')->nullable()->constrained('repeaters')->nullOnDelete();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->boolean('has_coverage')->nullable();
            $table->string('referral_code', 30)->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('contacted_at')->nullable();
            $table->dateTime('converted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('installation_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('folio', 30);
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('cotizacion')->index(); // cotizacion, aceptada, programada, en_proceso, instalada, activada, cancelada
            $table->decimal('quote_amount', 12, 2)->default(0);
            $table->json('quote_items')->nullable();
            $table->dateTime('quote_accepted_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->json('checklist')->nullable();
            $table->decimal('signal_dbm', 6, 2)->nullable();
            $table->decimal('speed_down', 8, 2)->nullable();
            $table->decimal('speed_up', 8, 2)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('priority', 10); // critica, alta, media, baja
            $table->unsignedInteger('resolution_minutes');
            $table->unsignedTinyInteger('warn_percent')->default(75);
            $table->timestamps();
            $table->unique(['tenant_id', 'priority']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('folio', 30)->index();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 30); // sin_internet, lento, antena, router, pago, instalacion, domicilio, plan, infraestructura, otro
            $table->string('priority', 10)->default('media');
            $table->string('status', 15)->default('abierto')->index(); // abierto, asignado, en_proceso, en_espera, resuelto, cerrado, cancelado
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('source', 15)->default('admin'); // admin, cliente, automatico, whatsapp, telefono
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installation_order_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('sla_due_at')->nullable()->index();
            $table->boolean('sla_breached')->default(false);
            $table->boolean('sla_warned')->default(false);
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->string('resolution')->nullable();
            $table->string('signature_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 15)->default('comment'); // comment, status, assignment, system
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 60);
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind', 20)->default('otro'); // antes, durante, despues, equipo, instalacion, firma, comprobante, otro
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 80)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->dateTime('taken_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('field_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 15)->default('tecnico'); // tecnico, cobranza
            $table->date('date')->index();
            $table->string('status', 15)->default('planned'); // planned, in_progress, completed
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_route_id')->constrained()->cascadeOnDelete();
            $table->string('stoppable_type', 60);
            $table->unsignedBigInteger('stoppable_id');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('status', 15)->default('pending'); // pending, arrived, done, skipped
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 40);
            $table->string('name');
            $table->string('category', 20); // antena, cpe, router, cable, conector, fuente, poe, torre, herraje, refaccion
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('unit', 10)->default('pza');
            $table->decimal('stock', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->decimal('max_stock', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('serialized')->default(false);
            $table->string('location')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15); // entrada, salida, instalacion, devolucion, ajuste
            $table->decimal('quantity', 12, 2); // positivo entra, negativo sale
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('stock_after', 12, 2)->nullable();
            $table->string('serial')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installation_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['inventory_movements', 'inventory_items', 'route_stops', 'field_routes', 'attachments', 'ticket_comments', 'tickets', 'sla_policies', 'installation_orders', 'prospects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
