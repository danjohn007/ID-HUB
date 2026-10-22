<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Message;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Messaging\FcmClient;
use App\Services\Messaging\MessageService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Notificaciones push por Firebase Cloud Messaging (HTTP v1): avisos al cliente, al personal
 * de campo al asignarle trabajo, baja de dispositivos desinstalados y tokens de Expo.
 */
class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected string $credentials;

    protected function setUp(): void
    {
        parent::setUp();
        $pem = $this->privateKey();
        $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentials, json_encode([
            'type' => 'service_account', 'project_id' => 'idhub-test', 'private_key' => $pem,
            'client_email' => 'push@idhub-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        config(['services.firebase.credentials' => $this->credentials]);
        $this->app->forgetInstance(FcmClient::class);

        $this->tenant = Tenant::create(['name' => 'WISP Prueba', 'slug' => 'wisp-prueba']);
        $this->tenant->putSettings('messaging', ['push_provider' => 'fcm']);
        app(TenantContext::class)->set($this->tenant);
    }

    /** Llave RSA de prueba. En Windows (XAMPP) OpenSSL necesita la ruta de su openssl.cnf. */
    protected function privateKey(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        foreach ([null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', getenv('OPENSSL_CONF') ?: null] as $cnf) {
            if ($cnf !== null && ! is_file($cnf)) {
                continue;
            }
            $config = $cnf ? ['config' => $cnf] : [];
            if (($key = @openssl_pkey_new($options + $config)) && @openssl_pkey_export($key, $pem, null, $config)) {
                return $pem;
            }
        }
        $this->markTestSkipped('OpenSSL no puede generar llaves en este equipo.');
    }

    protected function tearDown(): void
    {
        @unlink($this->credentials);
        parent::tearDown();
    }

    protected function fakeFirebase(array $fcm = ['name' => 'projects/idhub-test/messages/0:123'], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response($fcm, $status),
        ]);
    }

    protected function technician(): User
    {
        return User::create(['tenant_id' => $this->tenant->id, 'name' => 'Luis Técnico', 'email' => 'luis@test.com', 'password' => bcrypt('x'), 'role' => 'tecnico', 'active' => true]);
    }

    public function test_client_notice_is_sent_through_fcm_v1(): void
    {
        $this->fakeFirebase();
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2037', 'first_name' => 'Jonathan', 'phone' => '4461209731']);
        PushToken::create(['client_id' => $client->id, 'token' => 'fcm-token-cliente-123', 'platform' => 'android']);

        $sent = app(MessageService::class)->notify($client, 'reconnected', [], ['push']);

        $this->assertSame('sent', $sent->first()->fresh()->status);
        $this->assertSame('projects/idhub-test/messages/0:123', $sent->first()->fresh()->provider_id);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2.googleapis.com') && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/idhub-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'fcm-token-cliente-123'
            && $r['message']['notification']['title'] === 'Servicio reactivado'
            && str_contains($r['message']['notification']['body'], 'Jonathan')
            && $r['message']['data']['event'] === 'reconnected');
    }

    public function test_uninstalled_device_is_removed(): void
    {
        $this->fakeFirebase(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2038', 'first_name' => 'Ana']);
        PushToken::create(['client_id' => $client->id, 'token' => 'fcm-token-viejo', 'platform' => 'android']);

        $m = app(MessageService::class)->notify($client, 'welcome', [], ['push'])->first()->fresh();

        $this->assertSame('failed', $m->status);
        $this->assertStringContainsString('ya no está registrado', $m->error);
        $this->assertDatabaseMissing('push_tokens', ['token' => 'fcm-token-viejo']);
    }

    public function test_simulated_mode_does_not_call_firebase(): void
    {
        Http::fake();
        $this->tenant->putSettings('messaging', ['push_provider' => 'simulated']);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2039', 'first_name' => 'Eva']);
        PushToken::create(['client_id' => $client->id, 'token' => 'fcm-token-eva', 'platform' => 'ios']);

        $this->assertSame('simulated', app(MessageService::class)->notify($client, 'welcome', [], ['push'])->first()->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_missing_firebase_credentials_fail_with_a_clear_error(): void
    {
        config(['services.firebase.credentials' => storage_path('no-existe.json')]);
        $this->app->forgetInstance(FcmClient::class);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2040', 'first_name' => 'Iván']);
        PushToken::create(['client_id' => $client->id, 'token' => 'fcm-token-ivan', 'platform' => 'android']);

        $m = app(MessageService::class)->notify($client, 'welcome', [], ['push'])->first()->fresh();

        $this->assertSame('failed', $m->status);
        $this->assertStringContainsString('Firebase no está configurado', $m->error);
    }

    public function test_expo_tokens_go_through_expo(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'expo-1']])]);
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2041', 'first_name' => 'Raúl']);
        PushToken::create(['client_id' => $client->id, 'token' => 'ExponentPushToken[abc123]', 'platform' => 'android']);

        $this->assertSame('sent', app(MessageService::class)->notify($client, 'welcome', [], ['push'])->first()->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'exp.host') && json_decode($r->body(), true)['data']['event'] === 'welcome');
    }

    public function test_technician_gets_a_push_when_a_ticket_is_assigned(): void
    {
        $this->fakeFirebase();
        $tech = $this->technician();
        PushToken::create(['user_id' => $tech->id, 'token' => 'fcm-token-tecnico', 'platform' => 'android']);
        $ticket = Ticket::create(['folio' => 'T-000077', 'category' => 'sin_internet', 'subject' => 'Sin Internet', 'status' => 'abierto']);

        $ticket->update(['technician_id' => $tech->id, 'status' => 'asignado']);
        $ticket->update(['priority' => 'alta']); // otros cambios no repiten el aviso

        $pushes = Message::query()->where('channel', 'push')->where('event', 'staff')->get();
        $this->assertCount(1, $pushes);
        $this->assertSame('sent', $pushes->first()->status);
        $this->assertSame(['type' => 'ticket', 'id' => (string) $ticket->id], $pushes->first()->meta['data']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'fcm.googleapis.com')
            && $r['message']['notification']['title'] === 'Nuevo ticket asignado'
            && $r['message']['data']['type'] === 'ticket' && $r['message']['data']['id'] === (string) $ticket->id);
    }

    public function test_technician_gets_a_push_for_a_new_installation(): void
    {
        $this->fakeFirebase();
        $tech = $this->technician();
        PushToken::create(['user_id' => $tech->id, 'token' => 'fcm-token-tecnico', 'platform' => 'android']);

        $order = InstallationOrder::create(['folio' => 'INS-0009', 'status' => 'programada', 'technician_id' => $tech->id, 'scheduled_at' => now()->addDay()]);

        $push = Message::query()->where('channel', 'push')->sole();
        $this->assertSame('Nueva instalación asignada', $push->subject);
        $this->assertSame(['type' => 'installation_order', 'id' => (string) $order->id], $push->meta['data']);
    }

    public function test_no_push_when_the_technician_assigns_it_to_themselves(): void
    {
        Http::fake();
        $tech = $this->technician();
        PushToken::create(['user_id' => $tech->id, 'token' => 'fcm-token-tecnico', 'platform' => 'android']);
        $this->actingAs($tech);

        Ticket::create(['folio' => 'T-000078', 'category' => 'lento', 'subject' => 'Lento', 'status' => 'asignado', 'technician_id' => $tech->id]);

        $this->assertSame(0, Message::query()->where('channel', 'push')->count());
    }

    public function test_check_validates_the_service_account(): void
    {
        $this->fakeFirebase();

        $this->assertSame(['project' => 'idhub-test', 'email' => 'push@idhub-test.iam.gserviceaccount.com'], app(FcmClient::class)->check());
    }
}
