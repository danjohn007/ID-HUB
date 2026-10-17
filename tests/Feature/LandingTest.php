<?php

namespace Tests\Feature;

use App\Livewire\Saas\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
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

        $this->assertDatabaseHas('demo_requests', [
            'email' => 'ana@ejemplo.com', 'company' => 'Red Comunitaria', 'status' => 'new', 'mail_sent' => true,
        ]);
    }

    public function test_demo_request_is_kept_for_the_panel_when_mail_fails(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP caído'));

        $this->postJson(route('demo.request'), ['name' => 'Luis', 'email' => 'luis@ejemplo.com'])->assertOk();

        $this->assertDatabaseHas('demo_requests', ['email' => 'luis@ejemplo.com', 'mail_sent' => false]);
    }

    public function test_new_wisp_form_keeps_stable_field_ids_and_fills_slug(): void
    {
        $admin = User::create(['name' => 'Super', 'email' => 'super@idhub.test', 'password' => 'secret123', 'role' => 'superadmin', 'active' => true]);

        // Livewire usa el id como llave del DOM: si cambiara en cada render el campo "brincaría" al escribir
        Livewire::actingAs($admin)->test(Dashboard::class)
            ->call('create')->assertSeeHtml('id="f_form_name"')
            ->set('form.name', 'ID Industrial')->assertSeeHtml('id="f_form_name"')
            ->set('form.admin_name', 'Jonathan Raso')->set('form.admin_email', 'jonathan@idhub.test')->set('form.admin_password', 'secreto123')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('tenants', ['name' => 'ID Industrial', 'slug' => 'id-industrial']);
    }

    public function test_demo_request_validates_and_blocks_bots(): void
    {
        $this->postJson(route('demo.request'), ['name' => '', 'email' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
        $this->postJson(route('demo.request'), ['name' => 'Bot', 'email' => 'bot@x.com', 'website' => 'spam'])->assertStatus(422);
    }
}
