<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashCut;
use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\InventoryItem;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Support\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

/**
 * API de la app móvil de técnicos y cobradores (secciones 23-27): jornada, tickets
 * (materiales, firma, estados), órdenes de instalación, cobranza en campo y corte de caja.
 */
class FieldAppApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DemoSeeder::class;

    /** Firma de prueba: PNG con un trazo, como el que envía el lienzo de firma de la app. */
    protected static function png(): string
    {
        $img = imagecreatetruecolor(300, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $ink = imagecolorallocate($img, 11, 27, 58);
        imagesetthickness($img, 3);
        imageline($img, 20, 90, 120, 30, $ink);
        imageline($img, 120, 30, 200, 95, $ink);
        imageline($img, 200, 95, 280, 40, $ink);
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tokenFor(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password', 'device_name' => 'PHPUnit'])
            ->assertOk()->json('token');
    }

    /** Ticket abierto con cliente asignado al técnico (lo crea si los datos demo no tienen uno). */
    protected function openTicketFor(User $tech): Ticket
    {
        $this->inTenantOf($tech);

        return Ticket::query()->open()->where('technician_id', $tech->id)->whereNotNull('client_id')->first()
            ?? app(TicketService::class)->create([
                'client_id' => Client::query()->whereNotNull('lat')->value('id'),
                'category' => 'lento', 'priority' => 'media', 'subject' => 'Internet lento (prueba)',
                'technician_id' => $tech->id, 'source' => 'admin',
            ]);
    }

    protected function inTenantOf(User $user): void
    {
        app(TenantContext::class)->set($user->tenant);
    }

    // ------------------------------------------------------------------ Técnico

    public function test_technician_day_lists_stops_and_can_start_the_day(): void
    {
        $tech = User::where('email', 'tecnico@wispcontrol.com')->firstOrFail();
        $token = $this->tokenFor($tech->email);

        $day = $this->withToken($token)->getJson('/api/v1/field/today')->assertOk()
            ->assertJsonStructure(['date', 'route', 'suggested', 'stops', 'summary' => ['stops', 'done', 'distance_km', 'progress'], 'maps_url', 'open_tickets']);

        foreach ($day->json('stops') as $stop) {
            $this->assertArrayHasKey('key', $stop);
            $this->assertArrayHasKey('title', $stop);
            $this->assertContains($stop['type'], ['ticket', 'installation_order', 'client']);
        }

        if (! $day->json('route') && $day->json('stops')) {
            $started = $this->withToken($token)->postJson('/api/v1/field/start-day')->assertOk();
            $this->assertNotNull($started->json('route.id'));
            $this->assertFalse($started->json('suggested'));
            $this->assertNotNull($started->json('stops.0.id'));
        }
    }

    public function test_technician_works_a_ticket_with_materials_signature_and_status_rules(): void
    {
        $tech = User::where('email', 'tecnico@wispcontrol.com')->firstOrFail();
        $token = $this->tokenFor($tech->email);
        $this->inTenantOf($tech);
        $ticket = $this->openTicketFor($tech);
        $item = InventoryItem::query()->where('active', true)->where('stock', '>=', 2)->firstOrFail();
        $stock = (float) $item->stock;

        $this->withToken($token)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('data.folio', $ticket->folio)
            ->assertJsonStructure(['data' => ['client_detail' => ['plan', 'references'], 'materials', 'attachments', 'comments', 'signature', 'navigation_url', 'open']]);

        $this->withToken($token)->getJson('/api/v1/inventory/items')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'unit', 'stock', 'stock_label']]]);

        // Material: descuenta inventario y se puede devolver
        $m = $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/materials", ['item_id' => $item->id, 'quantity' => 2, 'serial' => 'ABC-123'])
            ->assertCreated()->assertJsonPath('data.quantity', fn ($q) => (float) $q === 2.0)->json('data');
        $this->assertEquals($stock - 2, (float) $item->fresh()->stock);
        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/materials/{$m['id']}/return")->assertCreated();
        $this->assertEquals($stock, (float) $item->fresh()->stock);

        // Firma de conformidad
        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/signature", ['signature' => self::png(), 'signed_by' => 'Juan Pérez'])
            ->assertOk()->assertJsonPath('data.signature.signed_by_name', 'Juan Pérez');
        $this->withToken($token)->postJson("/api/v1/tickets/{$ticket->id}/signature", ['signature' => 'data:image/png;base64,AAAA', 'signed_by' => 'Juan Pérez'])
            ->assertUnprocessable();

        // Evidencia con GPS y eliminación
        $att = $this->withToken($token)->post("/api/v1/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->image('antes.jpg'), 'kind' => 'antes', 'lat' => 20.7, 'lng' => -100.4,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->withToken($token)->deleteJson("/api/v1/tickets/{$ticket->id}/attachments/{$att['id']}")->assertOk();

        // Estados: el técnico no cierra; para resolver describe la solución
        $this->withToken($token)->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'cerrado'])->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'resuelto'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->withToken($token)->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'resuelto', 'note' => 'Se realineó la antena'])
            ->assertOk()->assertJsonPath('data.status.value', 'resuelto');
    }

    public function test_technician_completes_an_installation_order(): void
    {
        $tech = User::where('email', 'tecnico@wispcontrol.com')->firstOrFail();
        $token = $this->tokenFor($tech->email);
        $this->inTenantOf($tech);
        $client = Client::query()->whereNotNull('lat')->firstOrFail();
        $order = InstallationOrder::create([
            'folio' => 'OI-TEST-1', 'client_id' => $client->id, 'technician_id' => $tech->id,
            'status' => 'programada', 'scheduled_at' => now()->addHour(), 'quote_amount' => 0,
        ]);

        $this->withToken($token)->getJson('/api/v1/installations')->assertOk()->assertJsonFragment(['folio' => 'OI-TEST-1']);
        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/checklist", ['key' => 'antena'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/start")->assertOk()->assertJsonPath('data.status.value', 'en_proceso');

        foreach (['antena', 'cableado', 'poe', 'router', 'configuracion'] as $key) {
            $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/checklist", ['key' => $key, 'done' => true])->assertOk();
        }
        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/complete")->assertUnprocessable()->assertJsonStructure(['missing']);

        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/measures", ['signal_dbm' => -58, 'speed_down' => 30, 'speed_up' => 8])->assertOk();
        $this->withToken($token)->post("/api/v1/installations/{$order->id}/attachments", ['file' => UploadedFile::fake()->image('antena.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/signature", ['signature' => self::png(), 'signed_by' => 'Ana López'])->assertOk();

        $item = InventoryItem::query()->where('active', true)->where('stock', '>=', 1)->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/materials", ['item_id' => $item->id, 'quantity' => 1])->assertCreated();

        $done = $this->withToken($token)->postJson("/api/v1/installations/{$order->id}/complete")->assertOk();
        $this->assertSame('instalada', $done->json('data.status.value'));
        $this->assertSame(100, $done->json('data.checklist_progress'));
        $this->assertCount(1, $done->json('data.materials'));

        // Otro técnico no la ve
        $other = User::forTenant()->where('role', 'tecnico')->where('id', '!=', $tech->id)->first();
        if ($other) {
            $otherToken = $other->createToken('t', ['staff', ...config('permissions.roles.tecnico')])->plainTextToken;
            $this->withToken($otherToken)->getJson("/api/v1/installations/{$order->id}")->assertForbidden();
        }
    }

    // ------------------------------------------------------------------ Cobrador

    public function test_collector_day_visit_payment_and_cash_cut(): void
    {
        $collector = User::where('email', 'cobrador@wispcontrol.com')->firstOrFail();
        $token = $this->tokenFor($collector->email);
        $this->inTenantOf($collector);

        $day = $this->withToken($token)->getJson('/api/v1/collection/today?lat=20.7&lng=-100.45')->assertOk()
            ->assertJsonStructure(['summary' => ['collected', 'payments', 'visits', 'pending', 'pending_amount'], 'counts', 'data', 'ordered_by']);
        $rows = collect($day->json('data'));
        $this->assertTrue($rows->isNotEmpty(), 'El cobrador demo debe tener clientes con saldo.');
        $first = $rows->first();
        $this->assertArrayHasKey('navigation_url', $first);
        $this->assertGreaterThan(0, $first['balance']);

        $this->withToken($token)->getJson("/api/v1/collection/clients/{$first['id']}")->assertOk()->assertJsonStructure(['data', 'charges', 'payments', 'visits']);

        // Promesa de pago con evidencia → pasa a "visitados hoy"
        $this->withToken($token)->post('/api/v1/collection/visits', [
            'client_id' => $first['id'], 'result' => 'promesa', 'promise_date' => now()->addDays(3)->toDateString(), 'promise_amount' => 100,
            'evidence' => UploadedFile::fake()->image('casa.jpg'), 'lat' => 20.7, 'lng' => -100.45,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.result.value', 'promesa');
        $visited = $this->withToken($token)->getJson('/api/v1/collection/today?view=visitados')->assertOk();
        $this->assertContains($first['id'], collect($visited->json('data'))->pluck('id')->all());

        // Cobro en efectivo → corte abierto
        $second = $rows->skip(1)->first() ?? $first;
        $paid = $this->withToken($token)->postJson('/api/v1/collection/payments', ['client_id' => $second['id'], 'amount' => 150, 'method' => 'efectivo'])
            ->assertCreated()->assertJsonStructure(['data' => ['folio', 'receipt_url'], 'client_balance', 'cash_cut' => ['folio', 'expected_amount']]);
        $this->assertNotNull($paid->json('data.receipt_url'));

        $open = $this->withToken($token)->getJson('/api/v1/collection/cash-cut')->assertOk();
        $this->assertSame('open', $open->json('data.status.value'));
        $expected = (float) $open->json('data.expected_amount');

        // Hacer el corte: queda cerrado por entregar, con el efectivo contado en notas
        $closed = $this->withToken($token)->postJson('/api/v1/collection/cash-cut/close', ['counted_amount' => $expected - 10])->assertOk();
        $this->assertSame('closed', $closed->json('data.status.value'));
        $this->assertStringContainsString('Efectivo contado por el cobrador', $closed->json('data.notes'));
        $this->assertNull(CashCut::query()->where('collector_id', $collector->id)->where('status', 'open')->whereNull('closed_at')->first());
        $this->withToken($token)->postJson('/api/v1/collection/cash-cut/close')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/collection/cash-cuts')->assertOk()->assertJsonPath('data.0.id', $closed->json('data.id'));

        // No puede cobrar a clientes de otro cobrador
        $foreign = Client::query()->where('collector_id', '!=', $collector->id)->whereNotNull('collector_id')->where('balance', '>', 0)->first();
        if ($foreign) {
            $this->withToken($token)->postJson('/api/v1/collection/payments', ['client_id' => $foreign->id, 'amount' => 50])->assertForbidden();
        }
    }

    public function test_collector_cannot_use_technician_endpoints(): void
    {
        $token = $this->tokenFor('cobrador@wispcontrol.com');
        $this->withToken($token)->getJson('/api/v1/installations')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/inventory/items')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/tickets')->assertForbidden();
    }

    // ------------------------------------------------------------------ Web (misma lógica compartida)

    public function test_web_collection_page_still_renders_with_shared_service(): void
    {
        $collector = User::where('email', 'cobrador@wispcontrol.com')->firstOrFail();
        $this->actingAs($collector);
        $this->inTenantOf($collector);

        LivewireTest::test(Livewire\Field\Collection::class)->assertOk()->assertSee('Mi cobranza')
            ->set('view', 'visitados')->assertOk();
    }

    public function test_web_ticket_signature_uses_shared_service(): void
    {
        $tech = User::where('email', 'tecnico@wispcontrol.com')->firstOrFail();
        $this->actingAs($tech);
        $this->inTenantOf($tech);
        $ticket = $this->openTicketFor($tech);

        LivewireTest::test(Livewire\Field\Work::class, ['ticket' => $ticket])
            ->set('signedBy', 'Juan Pérez')->set('signature', self::png())->call('saveSignature')->assertHasNoErrors();
        $this->assertSame('Juan Pérez', $ticket->fresh()->signed_by_name);

        LivewireTest::test(Livewire\Field\Work::class, ['ticket' => $ticket])
            ->set('signedBy', 'Juan Pérez')->set('signature', 'nada')->call('saveSignature')->assertHasErrors('signature');
    }
}
