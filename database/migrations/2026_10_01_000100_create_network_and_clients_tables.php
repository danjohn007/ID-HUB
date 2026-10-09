<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas, planes de Internet, infraestructura de red (torres, equipos, repetidores,
 * sectores) y clientes. Las relaciones circulares (equipo <-> cliente, equipo <-> repetidor)
 * se guardan como índices sin FK para simplificar el orden de creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('community')->nullable();
            $table->string('color', 9)->default('#1A7FF5');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('polygon')->nullable();
            $table->decimal('monthly_cost', 12, 2)->default(0); // costo operativo estimado (BI)
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('download_mbps', 8, 2);
            $table->decimal('upload_mbps', 8, 2);
            $table->decimal('price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(16);
            $table->boolean('price_includes_tax')->default(true);
            $table->string('periodicity', 20)->default('monthly'); // monthly, bimonthly, quarterly, semiannual, annual
            $table->unsignedInteger('data_cap_gb')->nullable();
            $table->unsignedTinyInteger('grace_days')->nullable(); // null = política del WISP
            $table->string('block_type', 20)->nullable(); // partial, total
            $table->decimal('late_fee', 12, 2)->nullable();
            $table->decimal('reconnection_fee', 12, 2)->default(0);
            $table->decimal('installation_fee', 12, 2)->default(0);
            $table->boolean('auto_reconnect')->default(true);
            $table->string('allowed_equipment')->nullable();
            $table->string('mikrotik_profile', 80)->nullable();
            $table->string('color', 9)->default('#1A7FF5');
            $table->boolean('active')->default(true);
            $table->boolean('public')->default(true); // visible en portal / prospectos
            $table->timestamps();
        });

        Schema::create('towers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('height_m', 6, 1)->nullable();
            $table->string('owner')->nullable();
            $table->decimal('monthly_cost', 12, 2)->default(0);
            $table->string('status', 20)->default('online');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->index(); // mikrotik, antenna, cpe, ap, router, switch, ups, power, other
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('mac', 17)->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('firmware')->nullable();
            $table->string('location')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tower_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('repeater_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_item_id')->nullable()->index();
            $table->string('status', 20)->default('unknown')->index(); // online, warning, offline, unknown, retired, in_stock
            $table->date('installed_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            // Métricas más recientes
            $table->decimal('cpu', 5, 2)->nullable();
            $table->decimal('memory', 5, 2)->nullable();
            $table->decimal('latency_ms', 8, 2)->nullable();
            $table->decimal('packet_loss', 5, 2)->nullable();
            $table->decimal('signal_dbm', 6, 2)->nullable();
            $table->string('uptime')->nullable();
            // Conexión MikroTik (solo type = mikrotik)
            $table->string('api_mode', 10)->nullable(); // api, rest, push, simulated
            $table->string('api_host')->nullable();
            $table->unsignedSmallInteger('api_port')->nullable();
            $table->boolean('api_ssl')->default(false);
            $table->string('api_user')->nullable();
            $table->text('api_password')->nullable(); // cifrado
            $table->string('push_token', 64)->nullable()->unique();
            $table->string('service_method', 20)->nullable(); // pppoe, hotspot, dhcp, queue
            $table->string('suspended_list', 60)->nullable(); // address-list de morosos
            $table->dateTime('last_sync_at')->nullable();
            $table->string('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('repeaters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tower_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_device_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->foreignId('ap_device_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('location')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('capacity')->default(100);
            $table->unsignedTinyInteger('alert_threshold')->default(85); // % para alerta
            $table->decimal('coverage_radius_km', 6, 2)->default(3);
            $table->decimal('bandwidth_mbps', 10, 2)->nullable();
            $table->decimal('monthly_cost', 12, 2)->default(0);
            $table->string('status', 20)->default('online')->index(); // online, warning, offline, maintenance
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('down_since')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tower_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('azimuth')->default(0);
            $table->unsignedSmallInteger('beamwidth')->default(90);
            $table->decimal('range_km', 6, 2)->default(3);
            $table->decimal('height_m', 6, 1)->nullable();
            $table->string('frequency')->nullable();
            $table->decimal('power_dbm', 6, 2)->nullable();
            $table->string('technology')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('curp', 20)->nullable();
            $table->string('rfc', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('references')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('community')->nullable();
            $table->string('colonia')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('installation_date')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cpe_device_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_device_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('mac_address', 17)->nullable()->index();
            $table->string('pppoe_user', 80)->nullable()->index();
            $table->text('pppoe_password')->nullable(); // cifrado
            $table->string('service_status', 30)->default('activo')->index();
            $table->unsignedTinyInteger('billing_day')->default(1);
            $table->decimal('balance', 12, 2)->default(0); // saldo pendiente (positivo = debe)
            $table->decimal('credit_balance', 12, 2)->default(0); // saldo a favor
            $table->date('next_due_date')->nullable();
            $table->string('risk_level', 10)->default('bajo');
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->string('referral_code', 30)->nullable()->unique();
            $table->unsignedBigInteger('referred_by_client_id')->nullable()->index();
            $table->string('portal_password')->nullable();
            $table->boolean('online')->default(false);
            $table->dateTime('last_online_at')->nullable();
            $table->decimal('signal_dbm', 6, 2)->nullable();
            $table->decimal('month_usage_gb', 10, 2)->default(0);
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'service_status']);
        });

        Schema::create('service_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index(); // suspension, reconnection, plan_change, cancellation, activation, speed_change
            $table->string('reason')->nullable();
            $table->string('source', 20)->default('manual'); // manual, automation, payment, system
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('network_applied')->default(false);
            $table->string('network_result')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('monitoring_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 20); // device, repeater
            $table->unsignedBigInteger('subject_id');
            $table->boolean('online')->default(true);
            $table->decimal('cpu', 5, 2)->nullable();
            $table->decimal('memory', 5, 2)->nullable();
            $table->decimal('latency_ms', 8, 2)->nullable();
            $table->decimal('packet_loss', 5, 2)->nullable();
            $table->decimal('signal_dbm', 6, 2)->nullable();
            $table->unsignedBigInteger('rx_bps')->nullable();
            $table->unsignedBigInteger('tx_bps')->nullable();
            $table->unsignedInteger('connected_clients')->nullable();
            $table->dateTime('sampled_at')->index();
            $table->index(['subject_type', 'subject_id', 'sampled_at'], 'mon_subject_time');
        });

        Schema::create('outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('affected_clients')->default(0);
            $table->dateTime('started_at');
            $table->dateTime('last_communication_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->string('cause')->nullable();
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('severity', 10)->default('warning'); // info, warning, critical
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedupe_key', 150)->nullable()->index();
            $table->string('status', 15)->default('open')->index(); // open, acknowledged, resolved
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['alerts', 'outages', 'monitoring_samples', 'service_events', 'clients', 'sectors', 'repeaters', 'network_devices', 'towers', 'plans', 'zones'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
