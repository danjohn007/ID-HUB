<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM de ventas de ID-Hub (panel superadmin): las solicitudes de demo pasan a ser prospectos con
 * etapa, responsable, siguiente paso y vínculo con el WISP creado; se agregan historial de
 * actividades y agenda de demos. Compatible con MySQL 5.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_requests', function (Blueprint $table) {
            $table->string('email', 160)->nullable()->change(); // los capturados a mano pueden traer solo teléfono
            $table->string('source', 20)->default('landing')->after('message');
            $table->string('location', 120)->nullable()->after('company');
            $table->unsignedInteger('clients_count')->nullable()->after('clients');
            $table->string('current_software', 80)->nullable()->after('clients_count');
            $table->boolean('uses_mikrotik')->nullable()->after('current_software');
            $table->foreignId('saas_plan_id')->nullable()->after('uses_mikrotik')->constrained('saas_plans')->nullOnDelete();
            $table->decimal('estimated_mrr', 10, 2)->nullable()->after('saas_plan_id');
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('max_stage')->default(0)->after('assigned_to'); // etapa más avanzada alcanzada (embudo)
            $table->string('next_action', 190)->nullable()->after('notes');
            $table->dateTime('next_action_at')->nullable()->index()->after('next_action');
            $table->string('lost_reason', 30)->nullable()->after('next_action_at');
            $table->foreignId('tenant_id')->nullable()->after('lost_reason')->constrained('tenants')->nullOnDelete();
            $table->timestamp('stage_changed_at')->nullable()->after('contacted_at');
            $table->timestamp('won_at')->nullable()->after('stage_changed_at');
            $table->timestamp('lost_at')->nullable()->after('won_at');
        });

        // Estados de la primera versión → etapas del CRM
        DB::table('demo_requests')->where('status', 'discarded')->update(['status' => 'lost', 'lost_at' => DB::raw('updated_at')]);
        DB::table('demo_requests')->where('status', 'contacted')->update(['max_stage' => 1]);

        Schema::create('demo_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // note · call · whatsapp · email · meeting · stage · system
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('demo_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demo_request_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at')->index();
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->string('meeting_url', 255)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('scheduled')->index(); // scheduled · done · no_show · cancelled
            $table->text('notes')->nullable();
            $table->boolean('booked_by_prospect')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_meetings');
        Schema::dropIfExists('demo_activities');
        Schema::table('demo_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('saas_plan_id');
            $table->dropColumn(['source', 'location', 'clients_count', 'current_software', 'uses_mikrotik', 'estimated_mrr', 'max_stage',
                'next_action', 'next_action_at', 'lost_reason', 'stage_changed_at', 'won_at', 'lost_at']);
        });
    }
};
