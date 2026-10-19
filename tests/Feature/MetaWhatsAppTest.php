<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Message;
use App\Models\NotificationTemplate;
use App\Models\Tenant;
use App\Services\Messaging\MessageService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp Cloud API (Meta): plantillas aprobadas, texto libre, errores y webhook de estados.
 */
class MetaWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'WISP Prueba', 'slug' => 'wisp-prueba']);
        $this->tenant->putSettings('messaging', [
            'whatsapp_provider' => 'meta', 'whatsapp_phone_id' => '1234567890', 'whatsapp_token' => 'EAAtoken',
            'whatsapp_language' => 'es_MX', 'whatsapp_app_secret' => 'app-secret', 'whatsapp_verify_token' => 'verifica-123',
        ]);
        app(TenantContext::class)->set($this->tenant);
    }

    protected function fakeMeta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);
    }

    public function test_notice_is_sent_with_the_approved_template(): void
    {
        $this->fakeMeta();
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2037', 'first_name' => 'Jonathan', 'phone' => '446 120 9731', 'balance' => 350]);
        NotificationTemplate::create([
            'event' => 'pending', 'channel' => 'whatsapp', 'active' => true,
            'body' => "Hola {nombre}, tu saldo es {saldo}.\nPaga en {link_pago} hoy. Gracias.",
            'provider_template' => 'pago_pendiente',
        ]);

        $sent = app(MessageService::class)->notify($client, 'pending', ['link_pago' => 'https://pago.test/x'], ['whatsapp']);

        $this->assertSame('sent', $sent->first()->fresh()->status);
        $this->assertSame('wamid.ABC', $sent->first()->fresh()->provider_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/1234567890/messages')
            && $r->hasHeader('Authorization', 'Bearer EAAtoken')
            && $r['to'] === '524461209731' && $r['type'] === 'template'
            && $r['template']['name'] === 'pago_pendiente' && $r['template']['language']['code'] === 'es_MX'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['Jonathan', money(350), 'https://pago.test/x']);
    }

    public function test_message_without_template_is_free_text(): void
    {
        $this->fakeMeta();
        app(MessageService::class)->queue($this->tenant, 'whatsapp', '+524461209731', 'Hola, ¿cómo va tu servicio?');

        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'text' && $r['text']['body'] === 'Hola, ¿cómo va tu servicio?');
    }

    public function test_meta_errors_are_explained(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400)]);
        $m = app(MessageService::class)->queue($this->tenant, 'whatsapp', '+524461209731', 'Hola');

        $this->assertSame('failed', $m->fresh()->status);
        $this->assertStringContainsString('lista de destinatarios permitidos', $m->fresh()->error);
    }

    public function test_check_meta_reads_the_phone_number(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+52 446 120 9731', 'verified_name' => 'WISP Prueba', 'quality_rating' => 'GREEN'])]);

        $this->assertSame(['number' => '+52 446 120 9731', 'name' => 'WISP Prueba', 'quality' => 'GREEN'], app(MessageService::class)->checkMeta('1234567890', 'EAAtoken'));
    }

    public function test_webhook_verification(): void
    {
        $url = '/webhooks/whatsapp/'.$this->tenant->id;
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=verifica-123&hub.challenge=987')->assertOk()->assertSeeText('987');
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=987')->assertForbidden();
    }

    protected function statuses(array $statuses, string $secret = 'app-secret'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => $statuses]]]]]]);

        return $this->call('POST', '/webhooks/whatsapp/'.$this->tenant->id, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    public function test_webhook_updates_delivery_status(): void
    {
        $m = Message::create(['channel' => 'whatsapp', 'to' => '+524461209731', 'body' => 'x', 'status' => 'sent', 'provider_id' => 'wamid.ABC']);

        $this->statuses([['id' => 'wamid.ABC', 'status' => 'delivered']])->assertOk();
        $this->assertSame('delivered', $m->fresh()->status);

        $this->statuses([['id' => 'wamid.ABC', 'status' => 'sent']])->assertOk();
        $this->assertSame('delivered', $m->fresh()->status);

        $this->statuses([['id' => 'wamid.ABC', 'status' => 'failed', 'errors' => [['code' => 131047, 'title' => 'Re-engagement message']]]])->assertOk();
        $this->assertSame('failed', $m->fresh()->status);
        $this->assertStringContainsString('ventana de 24 h', $m->fresh()->error);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $m = Message::create(['channel' => 'whatsapp', 'to' => '+524461209731', 'body' => 'x', 'status' => 'sent', 'provider_id' => 'wamid.ABC']);

        $this->statuses([['id' => 'wamid.ABC', 'status' => 'delivered']], 'otro-secreto')->assertForbidden();
        $this->assertSame('sent', $m->fresh()->status);
    }

    public function test_settings_save_meta_and_test_connection(): void
    {
        $settings = $this->tenant->settings;
        unset($settings['messaging']['whatsapp_verify_token']);
        $this->tenant->update(['settings' => $settings]);
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+52 446 120 9731', 'verified_name' => 'WISP Prueba'])]);

        \Livewire\Livewire::test(\App\Livewire\Settings\Index::class, ['tab' => 'mensajeria'])
            ->assertSee('Webhook (estado de entrega)')
            ->assertSee('/webhooks/whatsapp/'.$this->tenant->id)
            ->call('testMeta')
            ->call('saveMessaging')->assertHasNoErrors();

        $cfg = $this->tenant->fresh()->settingsFor('messaging');
        $this->assertSame(40, strlen((string) $cfg['whatsapp_verify_token']));
        $this->assertSame('app-secret', $cfg['whatsapp_app_secret']);
        $this->assertSame('EAAtoken', $cfg['whatsapp_token']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/1234567890?fields='));
    }

    public function test_templates_screen_asks_for_meta_template_name(): void
    {
        \Livewire\Livewire::test(\App\Livewire\Communications\Templates::class, ['event' => 'reminder', 'channel' => 'whatsapp'])
            ->assertSee('Texto para Meta')
            ->assertSee('Nombre de la plantilla en Meta')
            ->set('form.provider_template', 'Recordatorio Pago')
            ->call('save')->assertHasErrors('form.provider_template')
            ->set('form.provider_template', 'recordatorio_pago')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('recordatorio_pago', NotificationTemplate::where('event', 'reminder')->where('channel', 'whatsapp')->value('provider_template'));
    }
}
