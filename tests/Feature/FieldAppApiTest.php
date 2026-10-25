<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\InstallationOrder;
use App\Models\InventoryItem;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Evidence;
use App\Support\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Endpoints de la app de campo (técnico): resumen del día, inventario, materiales,
 * firma y órdenes de instalación.
 */
class FieldAppApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = DemoSeeder::class;

    protected User $tech;

    protected string $token;

    /** PNG 1x1 válido (más de 100 bytes con los metadatos). */
    protected function png(): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->tech = User::where('email', 'tecnico@wispcontrol.com')->firstOrFail();
        app(TenantContext::class)->set($this->tech->tenant);
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => 'tecnico@wispcontrol.com', 'password' => 'password', 'device_name' => 'test',
        ])->assertOk()->json('token');
    }

    protected function api(): static
    {
        return $this->withToken($this->token);
    }

    protected function myTicket(): Ticket
    {
        $ticket = Ticket::query()->open()->whereNotNull('client_id')->firstOrFail();
        $ticket->update(['technician_id' => $this->tech->id]);

        return $ticket;
    }

    protected function myOrder(): InstallationOrder
    {
        $order = InstallationOrder::query()->whereNotIn('status', ['activada', 'cancelada'])->first()
            ?? InstallationOrder::create(['folio' => 'INS-T1', 'status' => 'programada']);
        $order->update(['technician_id' => $this->tech->id, 'status' => 'programada', 'checklist' => [], 'scheduled_at' => now()]);

        return $order;
    }

    public function test_today_includes_summary_and_installations(): void
    {
        $this->myTicket();
        $order = $this->myOrder();

        $res = $this->api()->getJson('/api/v1/field/today')->assertOk()
            ->assertJsonStructure(['date', 'type', 'summary' => ['services', 'clients', 'tickets', 'installations', 'done'], 'open_tickets', 'installations']);
        $this->assertSame('tecnico', $res->json('type'));
        $this->assertContains($order->id, collect($res->json('installations'))->pluck('id')->all());
    }

    public function test_inventory_lists_items_by_category(): void
    {
        $res = $this->api()->getJson('/api/v1/inventory')->assertOk()->assertJsonStructure(['categories', 'data' => [['id', 'name', 'stock', 'unit', 'category']]]);
        $this->assertNotEmpty($res->json('categories'));
    }

    public function test_ticket_material_is_discounted_and_returned(): void
    {
        $ticket = $this->myTicket();
        $item = InventoryItem::query()->where('active', true)->where('stock', '>=', 2)->firstOrFail();
        $stock = (float) $item->stock;

        $res = $this->api()->postJson("/api/v1/tickets/{$ticket->id}/materials", ['item_id' => $item->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.quantity', 2);
        $this->assertEquals($stock - 2, (float) $item->fresh()->stock);

        $this->api()->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->assertJsonCount(1, 'data.materials');

        $this->api()->deleteJson("/api/v1/tickets/{$ticket->id}/materials/".$res->json('data.id'))->assertOk();
        $this->assertEquals($stock, (float) $item->fresh()->stock);

        $this->api()->postJson("/api/v1/tickets/{$ticket->id}/materials", ['item_id' => $item->id, 'quantity' => $stock + 1000])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_ticket_signature_by_file_and_data_url(): void
    {
        $ticket = $this->myTicket();

        $this->api()->post("/api/v1/tickets/{$ticket->id}/signature", [
            'signed_by' => 'María López',
            'signature' => UploadedFile::fake()->createWithContent('firma.png', $this->png()),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.signature.signed_by', 'María López');
        Storage::disk('local')->assertExists($ticket->fresh()->signature_path);

        $this->api()->postJson("/api/v1/tickets/{$ticket->id}/signature", [
            'signed_by' => 'Juan Pérez', 'signature_data' => 'data:image/png;base64,'.base64_encode($this->png()),
        ])->assertCreated();

        $this->api()->postJson("/api/v1/tickets/{$ticket->id}/signature", ['signed_by' => 'Juan Pérez', 'signature_data' => 'data:image/png;base64,AAAA'])
            ->assertStatus(422)->assertJsonValidationErrors('signature');
    }

    public function test_cannot_work_ticket_of_other_technician(): void
    {
        $ticket = Ticket::query()->open()->firstOrFail();
        $ticket->update(['technician_id' => null]);

        $this->api()->postJson("/api/v1/tickets/{$ticket->id}/signature", ['signed_by' => 'X Y Z', 'signature_data' => 'x'])->assertForbidden();
    }

    public function test_installation_full_field_flow(): void
    {
        $order = $this->myOrder();
        $url = "/api/v1/installations/{$order->id}";

        $this->api()->getJson('/api/v1/installations')->assertOk()->assertJsonFragment(['id' => $order->id]);
        $this->api()->postJson("$url/start")->assertOk()->assertJsonPath('data.status.value', 'en_proceso');

        // No se puede finalizar con checklist incompleto
        $this->api()->postJson("$url/complete")->assertStatus(422)->assertJsonValidationErrors('checklist');

        foreach (['antena', 'cableado', 'poe', 'router', 'configuracion'] as $key) {
            $this->api()->patchJson("$url/checklist", ['key' => $key, 'done' => true])->assertOk();
        }
        $this->api()->patchJson("$url/checklist", ['key' => 'firma', 'done' => true])->assertStatus(422);
        $this->api()->patchJson("$url/measures", ['signal_dbm' => -58, 'speed_down' => 30, 'speed_up' => 8])->assertOk()
            ->assertJsonPath('data.measures.signal_dbm', -58);

        $this->api()->post("$url/attachments", [
            'file' => UploadedFile::fake()->image('antena.jpg'), 'kind' => 'instalacion', 'lat' => 20.75, 'lng' => -100.45,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->api()->postJson("$url/signature", ['signed_by' => 'Cliente Prueba', 'signature_data' => 'data:image/png;base64,'.base64_encode($this->png())])
            ->assertCreated();
        // Firma en disco privado; los PDF la embeben en base64
        Storage::disk('local')->assertExists($order->fresh()->signature_path);
        $this->assertStringStartsWith('data:image/png;base64,', Evidence::dataUri($order->fresh()->signature_path));

        $res = $this->api()->getJson($url)->assertOk();
        $this->assertTrue(collect($res->json('data.checklist'))->every(fn ($c) => $c['done']));

        $this->api()->postJson("$url/complete")->assertOk()->assertJsonPath('data.status.value', 'instalada');
        $this->api()->postJson("$url/start")->assertStatus(422);
    }

    public function test_installation_of_other_technician_is_forbidden(): void
    {
        $order = $this->myOrder();
        $order->update(['technician_id' => null]);

        $this->api()->getJson("/api/v1/installations/{$order->id}")->assertForbidden();
    }

    public function test_evidence_is_private_and_served_by_signed_url(): void
    {
        $ticket = $this->myTicket();
        $res = $this->api()->post("/api/v1/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->image('antes.jpg'), 'kind' => 'antes', 'lat' => 20.5, 'lng' => -100.4,
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = Attachment::findOrFail($res->json('data.id'));
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        $url = $res->json('data.url');
        $this->assertStringContainsString('/api/v1/files/', $url);
        $this->assertStringContainsString('signature=', $url);

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->get($url)->assertOk();
        // Sin firma (o alterada) no se puede descargar
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        // Panel web: requiere sesión; sólo evidencias del WISP activo
        $this->get(route('evidence.show', ['path' => $attachment->path]))->assertRedirect(route('login'));
        $this->actingAs($this->tech)->get(route('evidence.show', ['path' => $attachment->path]))->assertOk();
        Storage::disk('local')->put('evidences/999999/otra.jpg', 'x');
        $this->get(route('evidence.show', ['path' => 'evidences/999999/otra.jpg']))->assertNotFound();
        Storage::disk('local')->put('backups/db.sql.gz', 'x');
        $this->get('/evidencias/backups/db.sql.gz')->assertNotFound();
    }

    public function test_legacy_public_evidence_keeps_its_public_url(): void
    {
        $ticket = $this->myTicket();
        Storage::disk('public')->put('evidences/'.$ticket->tenant_id.'/tickets/old.jpg', 'x');
        $a = Attachment::create(['attachable_type' => 'ticket', 'attachable_id' => $ticket->id, 'kind' => 'antes',
            'path' => 'evidences/'.$ticket->tenant_id.'/tickets/old.jpg', 'mime' => 'image/jpeg']);

        $this->assertSame(Storage::disk('public')->url($a->path), $a->url());
        $this->assertSame(Storage::disk('public')->url($a->path), $a->signedUrl());
    }

    public function test_attachment_taken_at_from_app_is_validated(): void
    {
        $ticket = $this->myTicket();
        $send = fn ($takenAt) => Attachment::findOrFail($this->api()->post("/api/v1/tickets/{$ticket->id}/attachments", [
            'file' => UploadedFile::fake()->image('foto.jpg'), 'kind' => 'durante', 'taken_at' => $takenAt,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id'))->taken_at;

        // Captura de hace 2 h (UTC desde el teléfono): se conserva
        $this->assertEqualsWithDelta(now()->subHours(2)->timestamp, $send(now()->subHours(2)->utc()->toIso8601String())->timestamp, 2);
        // En el futuro o de hace más de 24 h: se usa la hora del servidor
        $this->assertEqualsWithDelta(now()->timestamp, $send(now()->addDay()->toIso8601String())->timestamp, 5);
        $this->assertEqualsWithDelta(now()->timestamp, $send(now()->subDays(3)->toIso8601String())->timestamp, 5);
    }

    public function test_replayed_field_actions_with_idempotency_key_are_not_repeated(): void
    {
        $ticket = $this->myTicket();
        $before = $ticket->comments()->count();

        $first = $this->api()->postJson("/api/v1/tickets/{$ticket->id}/comments", ['body' => 'Revisé la antena'], ['Idempotency-Key' => 'k-comment-1'])->assertCreated();
        $again = $this->api()->postJson("/api/v1/tickets/{$ticket->id}/comments", ['body' => 'Revisé la antena'], ['Idempotency-Key' => 'k-comment-1'])->assertCreated();
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame('true', $again->headers->get('Idempotent-Replayed'));
        $this->assertSame($before + 1, $ticket->comments()->count());

        // La misma clave en otra operación se rechaza
        $this->api()->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_proceso', 'client_uuid' => 'k-comment-1'])->assertStatus(422);
    }

    public function test_actions_sent_later_keep_the_time_they_happened(): void
    {
        $this->travelTo(now()->setTime(15, 0));
        $ticket = $this->myTicket();
        $ticket->update(['arrived_at' => null, 'departed_at' => null, 'status' => 'asignado']);
        $at = now()->subHours(3)->startOfMinute();

        // Llegada y comentario hechos sin señal a las 12:00 y enviados a las 15:00
        $this->api()->postJson("/api/v1/field/tickets/{$ticket->id}/arrive", ['occurred_at' => $at->copy()->utc()->toIso8601String(), 'lat' => 20.5, 'lng' => -100.4])->assertOk();
        $this->api()->postJson("/api/v1/tickets/{$ticket->id}/comments", ['body' => 'Sin señal en la zona', 'occurred_at' => $at->toIso8601String()])->assertCreated();
        $ticket->refresh();
        $this->assertTrue($ticket->arrived_at->equalTo($at));
        $this->assertSame('en_proceso', $ticket->status);
        $this->assertTrue($ticket->comments()->where('body', 'Sin señal en la zona')->first()->created_at->equalTo($at));
        $this->assertTrue($this->tech->fresh()->last_location_at->equalTo($at));

        // Una ubicación vieja no reemplaza a una más reciente
        $this->api()->postJson('/api/v1/field/location', ['lat' => 19.4, 'lng' => -99.1])->assertOk();
        $this->api()->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'en_espera', 'lat' => 21.0, 'lng' => -101.0, 'occurred_at' => $at->toIso8601String()])->assertOk();
        $this->assertEquals(19.4, (float) $this->tech->fresh()->last_lat);

        // Horas futuras o muy viejas (reloj del teléfono mal puesto) usan la del servidor
        $this->api()->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'resuelto', 'note' => 'Antena realineada', 'occurred_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertTrue($ticket->fresh()->resolved_at->equalTo(now()->startOfSecond()));

        $order = $this->myOrder();
        $this->api()->postJson("/api/v1/installations/{$order->id}/start", ['occurred_at' => $at->toIso8601String()])->assertOk();
        $this->assertTrue($order->fresh()->started_at->equalTo($at));
    }

    public function test_collector_cannot_use_technician_endpoints(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'cobrador@wispcontrol.com', 'password' => 'password', 'device_name' => 'test'])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/installations')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/inventory')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/field/today')->assertOk()->assertJsonPath('type', 'cobranza');
    }
}
