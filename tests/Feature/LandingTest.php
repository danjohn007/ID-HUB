<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_landing_with_plans(): void
    {
        $this->get('/')->assertOk()->assertSee('piloto automático')->assertSee('Agenda tu demo gratuita');
    }

    public function test_demo_request_is_emailed_to_sales(): void
    {
        config(['mail.default' => 'array', 'wisp.brand.sales_email' => 'dan@impactosdigitales.com']);
        Event::fake([MessageSent::class]);

        $this->postJson(route('demo.request'), [
            'name' => 'Ana Pérez', 'email' => 'ana@ejemplo.com', 'phone' => '4421234567',
            'company' => 'Red Comunitaria', 'clients' => '100 a 500', 'message' => 'Quiero automatizar cortes',
        ])->assertOk()->assertJsonStructure(['message']);

        Event::assertDispatched(MessageSent::class, function (MessageSent $e) {
            $email = $e->message;

            return $email->getTo()[0]->getAddress() === 'dan@impactosdigitales.com'
                && $email->getReplyTo()[0]->getAddress() === 'ana@ejemplo.com'
                && str_contains($email->getTextBody(), 'Red Comunitaria');
        });
    }

    public function test_demo_request_validates_and_blocks_bots(): void
    {
        $this->postJson(route('demo.request'), ['name' => '', 'email' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
        $this->postJson(route('demo.request'), ['name' => 'Bot', 'email' => 'bot@x.com', 'website' => 'spam'])->assertStatus(422);
    }
}
