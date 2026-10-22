<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\CashCut;
use App\Models\Client;
use App\Models\CollectionVisit;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * App del cobrador: cobros y visitas idempotentes (reintentos sin conexión), evidencia privada
 * con fecha/hora de captura y corte de caja (entregar y consultar historial).
 */
class CollectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DemoSeeder::class;

    protected User $collector;

    protected string $token;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->collector = User::where('email', 'cobrador@wispcontrol.com')->firstOrFail();
        app(TenantContext::class)->set($this->collector->tenant);
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => 'cobrador@wispcontrol.com', 'password' => 'password', 'device_name' => 'test',
        ])->assertOk()->json('token');
        $this->client = Client::query()->billable()->firstOrFail();
        $this->client->update(['collector_id' => $this->collector->id]);
    }

    protected function api(): static
    {
        return $this->withToken($this->token);
    }

    public function test_cash_payment_with_same_idempotency_key_is_not_duplicated(): void
    {
        $body = ['client_id' => $this->client->id, 'amount' => 150, 'lat' => 20.5, 'lng' => -100.4];

        $first = $this->api()->postJson('/api/v1/collection/payments', $body, ['Idempotency-Key' => 'pay-0001'])->assertCreated();
        $again = $this->api()->postJson('/api/v1/collection/payments', $body, ['Idempotency-Key' => 'pay-0001'])->assertOk()
            ->assertJsonPath('replayed', true);
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame($first->json('data.folio'), $again->json('data.folio'));
        $this->assertSame(1, Payment::query()->where('idempotency_key', 'pay-0001')->count());
        $this->assertSame(1, CollectionVisit::query()->where('payment_id', $first->json('data.id'))->count());

        // También como campo client_uuid (multipart / bandeja de la app)
        $byField = $this->api()->postJson('/api/v1/collection/payments', $body + ['client_uuid' => 'pay-0001'])->assertOk();
        $this->assertSame($first->json('data.id'), $byField->json('data.id'));

        // Otra clave = otro cobro
        $other = $this->api()->postJson('/api/v1/collection/payments', $body + ['client_uuid' => 'pay-0002'])->assertCreated();
        $this->assertNotSame($first->json('data.id'), $other->json('data.id'));

        // Sin clave se comporta como antes
        $this->api()->postJson('/api/v1/collection/payments', $body)->assertCreated();
    }

    public function test_visit_with_same_client_uuid_is_not_duplicated(): void
    {
        $body = ['client_id' => $this->client->id, 'result' => 'no_localizado', 'client_uuid' => 'visit-0001'];

        $first = $this->api()->postJson('/api/v1/collection/visits', $body)->assertCreated();
        $this->api()->postJson('/api/v1/collection/visits', $body)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, CollectionVisit::query()->where('idempotency_key', 'visit-0001')->count());
    }

    public function test_payment_evidence_is_private_with_capture_time(): void
    {
        $takenAt = now()->subMinutes(30);
        $res = $this->api()->post('/api/v1/collection/payments', [
            'client_id' => $this->client->id, 'amount' => 100, 'client_uuid' => 'pay-ev-1',
            'evidence' => UploadedFile::fake()->image('recibo.jpg'),
            'evidence_taken_at' => $takenAt->copy()->utc()->toIso8601String(), 'evidence_lat' => 20.1, 'evidence_lng' => -100.2,
        ], ['Accept' => 'application/json'])->assertCreated();

        $payment = Payment::findOrFail($res->json('data.id'));
        Storage::disk('local')->assertExists($payment->evidence_path);
        Storage::disk('public')->assertMissing($payment->evidence_path);
        $this->assertStringContainsString('signature=', $res->json('data.evidence_url'));

        $a = Attachment::query()->where('attachable_type', 'payment')->where('attachable_id', $payment->id)->firstOrFail();
        $this->assertEqualsWithDelta($takenAt->timestamp, $a->taken_at->timestamp, 2);
        $this->assertEquals(20.1, (float) $a->lat);

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->get($res->json('data.evidence_url'))->assertOk();
        $this->get(strtok($res->json('data.evidence_url'), '?'))->assertForbidden();
    }

    public function test_collector_closes_cash_cut_and_sees_history(): void
    {
        $this->api()->postJson('/api/v1/collection/payments', ['client_id' => $this->client->id, 'amount' => 200, 'client_uuid' => 'cut-1'])->assertCreated();

        $open = $this->api()->getJson('/api/v1/collection/cash-cut')->assertOk()->assertJsonPath('cash_cut.status.value', 'open');
        $cutId = $open->json('cash_cut.id');
        $expected = $open->json('cash_cut.expected_amount');
        $this->assertGreaterThanOrEqual(200, $expected);

        // El cobrador no registra el efectivo recibido (eso es del supervisor): delivered_amount se ignora
        $this->api()->postJson('/api/v1/collection/cash-cut/close', ['delivered_amount' => 1])->assertOk()
            ->assertJsonPath('data.id', $cutId)
            ->assertJsonPath('data.status.value', 'closed')
            ->assertJsonPath('data.delivered_amount', null);
        $this->assertNotNull(CashCut::find($cutId)->closed_at);

        $this->api()->getJson('/api/v1/collection/cash-cut')->assertOk()->assertJsonPath('cash_cut', null);
        $history = $this->api()->getJson('/api/v1/collection/cash-cuts')->assertOk();
        $this->assertSame($cutId, $history->json('data.0.id'));

        // Reintento inmediato: devuelve el corte ya cerrado; más tarde y sin corte abierto: error
        $this->api()->postJson('/api/v1/collection/cash-cut/close')->assertOk()->assertJsonPath('data.id', $cutId);
        $this->travel(30)->minutes();
        $this->api()->postJson('/api/v1/collection/cash-cut/close')->assertStatus(422)->assertJsonValidationErrors('cash_cut');

        // Un nuevo cobro abre otro corte
        $this->api()->postJson('/api/v1/collection/payments', ['client_id' => $this->client->id, 'amount' => 50])->assertCreated();
        $this->assertNotSame($cutId, $this->api()->getJson('/api/v1/collection/cash-cut')->json('cash_cut.id'));
    }

    public function test_cash_cut_history_only_shows_own_cuts_and_requires_permission(): void
    {
        $other = User::forTenant()->where('id', '!=', $this->collector->id)->firstOrFail();
        CashCut::create(['folio' => 'CC-OTRO-1', 'collector_id' => $other->id, 'opened_at' => now(), 'status' => 'open']);

        $folios = collect($this->api()->getJson('/api/v1/collection/cash-cuts')->assertOk()->json('data'))->pluck('folio');
        $this->assertNotContains('CC-OTRO-1', $folios);

        $tech = $this->postJson('/api/v1/auth/login', ['email' => 'tecnico@wispcontrol.com', 'password' => 'password', 'device_name' => 'test'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($tech)->getJson('/api/v1/collection/cash-cuts')->assertForbidden();
        $this->withToken($tech)->postJson('/api/v1/collection/cash-cut/close')->assertForbidden();
    }
}
