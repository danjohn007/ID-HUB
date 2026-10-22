<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Referral;
use App\Models\SaasInvoice;
use App\Models\SaasPlan;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AutomationEngine;
use App\Services\Billing\CollectionAutomation;
use App\Services\BillingService;
use App\Services\CaptivePortalService;
use App\Services\ReferralService;
use App\Services\SaasBillingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Políticas de cobranza por plan, aviso de pago pendiente, reconexión, recompensas de referidos,
 * funciones del plan SaaS, vigencia de suscripciones, dominio del WISP, SLA por categoría y
 * acceso por código del portal cautivo.
 */
class BillingPoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'WISP Prueba', 'slug' => 'wisp-prueba', 'status' => 'active']);
        $this->tenant->putSettings('billing', ['grace_days' => 3, 'reminder_hour' => '00:00', 'cut_hour' => '00:00']);
        app(TenantContext::class)->set($this->tenant);
    }

    protected function plan(array $attrs = []): Plan
    {
        return Plan::create($attrs + ['name' => 'Hogar', 'download_mbps' => 20, 'upload_mbps' => 5, 'price' => 350, 'tax_rate' => 16, 'price_includes_tax' => true, 'periodicity' => 'monthly']);
    }

    protected function client(Plan $plan, array $attrs = []): Client
    {
        static $n = 1000;

        return Client::create($attrs + ['tenant_id' => $this->tenant->id, 'code' => (string) ++$n, 'first_name' => 'Ana', 'phone' => '4461209731', 'plan_id' => $plan->id, 'service_status' => 'activo']);
    }

    protected function overdueCharge(Client $client, int $daysAgo): Charge
    {
        return Charge::create(['client_id' => $client->id, 'type' => 'mensualidad', 'description' => 'Servicio', 'subtotal' => 301.72, 'tax' => 48.28, 'total' => 350,
            'due_date' => today()->subDays($daysAgo)->toDateString(), 'status' => 'pending']);
    }

    public function test_quarterly_plan_charges_its_price_once_per_period(): void
    {
        $client = $this->client($this->plan(['price' => 900, 'periodicity' => 'quarterly']));

        $charge = app(BillingService::class)->chargePeriod($client, Carbon::parse('2026-11-01'));

        $this->assertSame(900.0, (float) $charge->total);
        $this->assertSame('2027-01-31', $charge->period_end->toDateString());
    }

    public function test_referral_discount_is_applied_to_the_next_charge(): void
    {
        $plan = $this->plan(['price' => 400]);
        $referrer = $this->client($plan);
        $referred = $this->client($plan);
        $referral = Referral::create(['referrer_client_id' => $referrer->id, 'referred_client_id' => $referred->id, 'code' => 'INVITA-1', 'status' => 'validado', 'reward_type' => 'descuento', 'reward_value' => 25]);

        app(ReferralService::class)->reward($referral);
        $this->assertSame(100.0, (float) $referrer->fresh()->pending_discount);

        $charge = app(BillingService::class)->chargePeriod($referrer->fresh(), Carbon::parse('2026-11-01'));
        $this->assertSame(300.0, (float) $charge->total);
        $this->assertStringContainsString('descuento', $charge->description);
        $this->assertSame(0.0, (float) $referrer->fresh()->pending_discount);
    }

    public function test_cutoff_uses_the_grace_days_of_the_plan(): void
    {
        $patient = $this->client($this->plan(['grace_days' => 10]));
        $strict = $this->client($this->plan(['name' => 'Básico']));
        $this->overdueCharge($patient, 5);
        $this->overdueCharge($strict, 5);

        $stats = app(CollectionAutomation::class)->enforceCutoffs($this->tenant, true);

        $this->assertSame(1, $stats['evaluated']);
        $this->assertNotNull($strict->fresh()->overdue_fired_on);
        $this->assertNull($patient->fresh()->overdue_fired_on);
    }

    public function test_pending_payment_notice_is_sent_once_after_the_due_date(): void
    {
        $this->tenant->putSettings('billing', ['pending_notice_days' => 1, 'auto_suspend' => false]);
        $client = $this->client($this->plan());
        $this->overdueCharge($client, 2);

        $first = app(CollectionAutomation::class)->sendReminders($this->tenant, true);
        $second = app(CollectionAutomation::class)->sendReminders($this->tenant, true);

        $this->assertSame(1, $first['pending']);
        $this->assertSame(0, $second['pending']);
        $this->assertSame(1, Message::query()->where('event', 'pending')->where('client_id', $client->id)->count());
    }

    protected function reconnectRule(): AutomationRule
    {
        return AutomationRule::create(['name' => 'Reconexión', 'event' => 'payment_received', 'conditions' => [],
            'actions' => [['type' => 'reconnect_service', 'params' => ['require_zero_balance' => true, 'notify' => false]]]]);
    }

    public function test_reconnection_respects_plan_and_settings(): void
    {
        $manual = $this->client($this->plan(['auto_reconnect' => false]), ['service_status' => 'suspendido', 'suspended_at' => now()]);
        $owing = $this->client($this->plan(['name' => 'Plus']), ['service_status' => 'suspendido', 'suspended_at' => now(), 'balance' => 100]);
        $old = $this->client($this->plan(['name' => 'Premium']), ['service_status' => 'suspendido', 'suspended_at' => now()->subDays(40)]);
        $rule = $this->reconnectRule();
        $engine = app(AutomationEngine::class);

        $this->assertStringContainsString('manualmente', implode(' ', $engine->runRule($rule, $manual)->log ?? []));

        $this->tenant->putSettings('billing', ['reconnect_requires_full_payment' => false, 'reconnect_max_days' => 30]);
        $engine->runRule($rule, $owing->fresh());
        $this->assertSame('activo', $owing->fresh()->service_status);

        $engine->runRule($rule, $old->fresh());
        $this->assertSame('suspendido', $old->fresh()->service_status);
    }

    public function test_modules_follow_the_saas_plan_features(): void
    {
        $plan = SaasPlan::create(['code' => 'STARTER', 'name' => 'Starter', 'price_monthly' => 799, 'features' => ['dashboard', 'cobranza'], 'active' => true]);
        $this->tenant->update(['saas_plan_id' => $plan->id]);
        $admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret123', 'role' => 'admin', 'active' => true]);
        app(TenantContext::class)->set($this->tenant->fresh());

        $this->assertFalse(Gate::forUser($admin)->allows('bi.view'));
        $this->assertFalse(Gate::forUser($admin)->allows('map.view'));
        $this->assertTrue(Gate::forUser($admin)->allows('billing.view'));

        $plan->update(['features' => ['*']]);
        app(TenantContext::class)->set($this->tenant->fresh());
        $this->assertTrue(Gate::forUser($admin)->allows('bi.view'));
    }

    public function test_expired_subscriptions_are_suspended_and_paying_reactivates(): void
    {
        $trial = Tenant::create(['name' => 'Prueba vencida', 'slug' => 'prueba', 'status' => 'trial', 'trial_ends_at' => today()->subDay()]);
        $grace = Tenant::create(['name' => 'En gracia', 'slug' => 'gracia', 'status' => 'active', 'subscription_ends_at' => today()->subDays(3)]);
        $late = Tenant::create(['name' => 'Vencida', 'slug' => 'vencida', 'status' => 'active', 'subscription_ends_at' => today()->subDays(30)]);

        $suspended = app(SaasBillingService::class)->enforceSubscriptions();

        $this->assertCount(2, $suspended);
        $this->assertSame('suspended', $trial->fresh()->status);
        $this->assertSame('active', $grace->fresh()->status);
        $this->assertSame('suspended', $late->fresh()->status);

        $invoice = SaasInvoice::create(['tenant_id' => $late->id, 'folio' => 'SAAS-1', 'period_start' => now()->subMonth()->startOfMonth(), 'period_end' => now()->subMonth()->endOfMonth(),
            'amount' => 799, 'total' => 799, 'status' => 'pending']);
        app(SaasBillingService::class)->markPaid($invoice);

        $this->assertSame('active', $late->fresh()->status);
        $this->assertSame(now()->endOfMonth()->toDateString(), $late->fresh()->subscription_ends_at->toDateString());
    }

    public function test_tenant_is_resolved_from_its_domain_or_subdomain(): void
    {
        config(['wisp.tenant_root_domain' => 'id-hub.digital']);
        $this->tenant->update(['domain' => 'portal.miwisp.mx', 'subdomain' => 'valle']);

        $this->assertSame($this->tenant->id, Tenant::forHost('portal.miwisp.mx')?->id);
        $this->assertSame($this->tenant->id, Tenant::forHost('valle.id-hub.digital')?->id);
        $this->assertNull(Tenant::forHost('id-hub.digital'));
        $this->assertNull(Tenant::forHost('otro.id-hub.digital'));
    }

    public function test_sla_uses_the_stricter_of_priority_and_category(): void
    {
        SlaPolicy::create(['priority' => 'media', 'category' => null, 'resolution_minutes' => 480, 'warn_percent' => 75]);
        SlaPolicy::create(['priority' => SlaPolicy::ANY_PRIORITY, 'category' => 'sin_internet', 'resolution_minutes' => 120, 'warn_percent' => 75]);

        $this->assertSame(120, SlaPolicy::minutesFor('media', 'sin_internet'));
        $this->assertSame(480, SlaPolicy::minutesFor('media', 'lento'));
    }

    public function test_captive_code_login_needs_the_phone_digits(): void
    {
        $client = $this->client($this->plan(), ['code' => 'C2037', 'phone' => '446 120 9731']);
        $portal = app(CaptivePortalService::class);

        $this->assertFalse($portal->authenticate($this->tenant, 'codigo', ['code' => 'C2037'], null, '10.0.0.5')['ok']);
        $this->assertFalse($portal->authenticate($this->tenant, 'codigo', ['code' => 'C2037', 'phone4' => '0000'], null, '10.0.0.5')['ok']);
        $this->assertTrue($portal->authenticate($this->tenant, 'codigo', ['code' => 'C2037', 'phone4' => '9731'], null, '10.0.0.5')['ok']);
        $this->assertSame($client->id, $portal->authenticate($this->tenant, 'codigo', ['code' => 'c2037', 'phone4' => '9731'], null, null)['client']->id);
    }

    public function test_changing_the_password_revokes_app_tokens(): void
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Técnico', 'email' => 'tec@test.com', 'password' => 'secret123', 'role' => 'tecnico', 'active' => true]);
        $user->createToken('app');
        $user->update(['last_login_at' => now()]);
        $this->assertSame(1, $user->tokens()->count());

        $user->update(['password' => 'otra-clave-123']);

        $this->assertSame(0, $user->tokens()->count());
    }
}
