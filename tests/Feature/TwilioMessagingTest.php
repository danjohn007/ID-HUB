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
 * Envío por Twilio (SMS y WhatsApp con plantilla aprobada) y callback de estado de entrega.
 */
class TwilioMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'WISP Prueba', 'slug' => 'wisp-prueba']);
        $this->tenant->putSettings('messaging', [
            'whatsapp_provider' => 'twilio', 'sms_provider' => 'twilio',
            'twilio_sid' => 'AC123', 'twilio_token' => 'secreto',
            'twilio_from' => '+15005550006', 'twilio_whatsapp_from' => '+14155238886',
        ]);
        app(TenantContext::class)->set($this->tenant);
    }

    protected function fakeTwilio(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM0001', 'status' => 'queued'], 201)]);
    }

    public function test_sms_is_sent_through_twilio(): void
    {
        $this->fakeTwilio();
        $m = app(MessageService::class)->queue($this->tenant, 'sms', '+524421234567', 'Hola');

        $this->assertSame('sent', $m->fresh()->status);
        $this->assertSame('SM0001', $m->fresh()->provider_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/Accounts/AC123/Messages.json')
            && $r['To'] === '+524421234567' && $r['From'] === '+15005550006' && $r['Body'] === 'Hola');
    }

    public function test_whatsapp_uses_the_approved_template_and_its_variables(): void
    {
        $this->fakeTwilio();
        $client = Client::create(['tenant_id' => $this->tenant->id, 'code' => '2037', 'first_name' => 'Jonathan', 'phone' => '4461209731', 'balance' => 350]);
        NotificationTemplate::create([
            'event' => 'pending', 'channel' => 'whatsapp', 'active' => true,
            'body' => 'Hola {nombre}, tu saldo es {saldo}. Paga en {link_pago} antes de mañana, {nombre}.',
            'provider_template' => 'HX'.str_repeat('a', 32),
        ]);

        app(MessageService::class)->notify($client, 'pending', ['link_pago' => 'https://pago.test/x'], ['whatsapp']);

        Http::assertSent(function (HttpRequest $r) {
            $vars = json_decode($r['ContentVariables'], true);

            return $r['To'] === 'whatsapp:+524461209731' && $r['From'] === 'whatsapp:+14155238886'
                && $r['ContentSid'] === 'HX'.str_repeat('a', 32) && ! isset($r['Body'])
                && $vars === ['1' => 'Jonathan', '2' => money(350), '3' => 'https://pago.test/x'];
        });
    }

    public function test_whatsapp_without_template_sends_free_text(): void
    {
        $this->fakeTwilio();
        app(MessageService::class)->queue($this->tenant, 'whatsapp', '+524461209731', 'Texto libre');

        Http::assertSent(fn (HttpRequest $r) => $r['Body'] === 'Texto libre' && ! isset($r['ContentSid']));
    }

    public function test_twilio_errors_are_explained(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21608, 'message' => 'The number is unverified'], 400)]);
        $m = app(MessageService::class)->queue($this->tenant, 'sms', '+524421234567', 'Hola');

        $this->assertSame('failed', $m->fresh()->status);
        $this->assertStringContainsString('números verificados', $m->fresh()->error);
    }

    public function test_provider_text_numbers_variables_in_order(): void
    {
        $this->assertSame('Hola {{1}}, debes {{2}}. Gracias {{1}}.', MessageService::providerText('Hola {nombre}, debes {saldo}. Gracias {nombre}.'));
    }

    protected function statusCallback(array $params, ?string $token = 'secreto'): \Illuminate\Testing\TestResponse
    {
        $url = url('/webhooks/twilio/status');
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return $this->post('/webhooks/twilio/status', $params, ['X-Twilio-Signature' => base64_encode(hash_hmac('sha1', $data, $token, true))]);
    }

    protected function sentMessage(): Message
    {
        return Message::create(['channel' => 'whatsapp', 'to' => '+524461209731', 'body' => 'x', 'status' => 'sent', 'provider_id' => 'SM0001']);
    }

    public function test_status_callback_marks_delivered_and_failed(): void
    {
        $m = $this->sentMessage();

        $this->statusCallback(['MessageSid' => 'SM0001', 'AccountSid' => 'AC123', 'MessageStatus' => 'delivered'])->assertNoContent();
        $this->assertSame('delivered', $m->fresh()->status);

        // Un "sent" tardío no regresa el estado
        $this->statusCallback(['MessageSid' => 'SM0001', 'AccountSid' => 'AC123', 'MessageStatus' => 'sent'])->assertNoContent();
        $this->assertSame('delivered', $m->fresh()->status);

        $this->statusCallback(['MessageSid' => 'SM0001', 'AccountSid' => 'AC123', 'MessageStatus' => 'undelivered', 'ErrorCode' => '63016'])->assertNoContent();
        $this->assertSame('failed', $m->fresh()->status);
        $this->assertStringContainsString('ventana de 24 h', $m->fresh()->error);
    }

    public function test_templates_screen_shows_twilio_text_and_saves_content_sid(): void
    {
        \Livewire\Livewire::test(\App\Livewire\Communications\Templates::class, ['event' => 'pending', 'channel' => 'whatsapp'])
            ->assertSee('Texto para Twilio')
            ->assertSee('Hola {{1}}, tu pago está pendiente', false)
            ->assertSee('no aprueba plantillas que empiezan o terminan')
            ->set('form.provider_template', 'HX123')
            ->call('save')->assertHasErrors('form.provider_template')
            ->set('form.provider_template', 'HX'.str_repeat('b', 32))
            ->call('save')->assertHasNoErrors();

        $this->assertSame('HX'.str_repeat('b', 32), NotificationTemplate::where('event', 'pending')->where('channel', 'whatsapp')->value('provider_template'));
    }

    public function test_status_callback_rejects_bad_signature(): void
    {
        $m = $this->sentMessage();

        $this->statusCallback(['MessageSid' => 'SM0001', 'AccountSid' => 'AC123', 'MessageStatus' => 'delivered'], 'otro-token')->assertForbidden();
        $this->assertSame('sent', $m->fresh()->status);
    }
}
