<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comunicación, automatizaciones, crecimiento (promociones, referidos, portal cautivo)
 * y auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40); // reminder, due, pending, suspension_warning, suspended, payment_received, reconnected, ticket_assigned, technician_on_way, ticket_updated, maintenance, outage, promotion, welcome
            $table->string('channel', 10); // whatsapp, sms, email, push
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'event', 'channel']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 10);
            $table->string('segment', 20); // all, zone, repeater, plan, morosos, activos, nuevos, suspendidos
            $table->unsignedBigInteger('segment_value')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 15)->default('draft'); // draft, scheduled, sending, sent, cancelled
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10);
            $table->string('event', 40)->nullable();
            $table->string('to');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 12)->default('queued')->index(); // queued, sent, delivered, failed, simulated
            $table->string('provider_id')->nullable();
            $table->string('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 255);
            $table->string('platform', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('repeater_id')->nullable()->constrained()->nullOnDelete();
            $table->json('device_ids')->nullable();
            $table->unsignedInteger('affected_clients')->default(0);
            $table->unsignedSmallInteger('notify_hours_before')->default(24);
            $table->dateTime('notified_at')->nullable();
            $table->string('status', 15)->default('scheduled'); // scheduled, in_progress, completed, cancelled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('event', 40)->index();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('active')->default(true);
            $table->boolean('system')->default(false);
            $table->unsignedInteger('run_count')->default(0);
            $table->dateTime('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 10)->default('success'); // success, skipped, failed
            $table->json('log')->nullable();
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('type', 20); // descuento_pct, descuento_monto, mes_gratis, instalacion_gratis
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedTinyInteger('months')->default(1);
            $table->json('plan_ids')->nullable();
            $table->string('code', 30)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('show_in_portal')->default(true);
            $table->string('image_path')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referrer_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('referred_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 30);
            $table->string('status', 15)->default('pendiente'); // pendiente, validado, recompensado, cancelado
            $table->string('reward_type', 20)->nullable(); // mes_gratis, descuento, saldo, promocion
            $table->decimal('reward_value', 12, 2)->default(0);
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('rewarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('captive_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('batch', 40)->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('data_limit_mb')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status', 10)->default('available'); // available, used, expired
            $table->string('mac', 17)->nullable();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('captive_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('advertiser');
            $table->string('business_type', 20)->default('comercio'); // comercio, restaurante, farmacia, escuela, servicio
            $table->string('campaign')->nullable();
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('url', 500)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('captive_ad_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('captive_ad_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['captive_ad_id', 'date']);
        });

        Schema::create('captive_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10); // usuario, codigo, voucher
            $table->string('identifier')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac', 17)->nullable();
            $table->string('ip', 45)->nullable();
            $table->boolean('success')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('action', 40)->index();
            $table->string('auditable_type', 60)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'captive_sessions', 'captive_ad_stats', 'captive_ads', 'captive_vouchers', 'referrals', 'promotions', 'automation_runs', 'automation_rules', 'maintenances', 'push_tokens', 'messages', 'campaigns', 'notification_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
