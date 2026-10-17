<?php

namespace Tests\Feature;

use App\Livewire\Saas\Dashboard;
use App\Livewire\Saas\Sales\Agenda;
use App\Livewire\Saas\Sales\Leads;
use App\Livewire\Saas\Sales\LeadShow;
use App\Livewire\Saas\Sales\MeetingForm;
use App\Mail\DemoMeetingMail;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use App\Models\SaasPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRM de ventas de ID-Hub (Panel SaaS → Ventas): prospectos, etapas, agenda de demos,
 * autoagenda desde la página pública, recordatorios y conversión a WISP de prueba.
 */
class SalesCrmTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00')); // lunes
        Mail::fake();
        $this->admin = User::create(['name' => 'Super Admin', 'email' => 'super@idhub.test', 'password' => 'secret123', 'role' => 'superadmin', 'active' => true]);
        SaasPlan::query()->update(['active' => false]); // los datos de demostración pueden traer otros planes
        foreach ([['STARTER', 'Starter', 799, 100, 1], ['PRO', 'Pro', 1999, 500, 2], ['ENTERPRISE', 'Enterprise', 4999, 5000, 3]] as [$code, $name, $price, $max, $sort]) {
            SaasPlan::updateOrCreate(['code' => $code], ['name' => $name, 'price_monthly' => $price, 'max_clients' => $max, 'active' => true, 'sort' => $sort]);
        }
    }

    protected function lead(array $attrs = []): DemoRequest
    {
        return DemoRequest::create($attrs + ['name' => 'Ana Pérez', 'email' => 'ana@ejemplo.com', 'phone' => '4421234567', 'company' => 'Red Sierra', 'clients' => '100 a 500']);
    }

    public function test_prospect_books_a_demo_from_the_public_page(): void
    {
        $this->get('/')->assertOk()->assertSee('¿Cuándo te gustaría la demo?')->assertSee('2026-10-20 10:00');

        $this->postJson(route('demo.request'), [
            'name' => 'Luis Gómez', 'email' => 'luis@ejemplo.com', 'company' => 'Conecta Norte', 'clients' => 'Menos de 100', 'slot' => '2026-10-20 10:00',
        ])->assertOk()->assertJsonFragment(['message' => '¡Listo! Tu demo quedó agendada para el martes 20 de octubre a las 10:00 h. Te enviamos la confirmación a tu correo.']);

        $lead = DemoRequest::where('email', 'luis@ejemplo.com')->firstOrFail();
        $this->assertSame('demo_scheduled', $lead->status);
        $meeting = $lead->meetings()->firstOrFail();
        $this->assertTrue($meeting->booked_by_prospect);
        $this->assertSame('2026-10-20 10:00', $meeting->starts_at->format('Y-m-d H:i'));
        Mail::assertSent(DemoMeetingMail::class, fn ($m) => $m->hasTo('luis@ejemplo.com') && $m->kind === 'confirmation');
        $this->assertSame('Starter', $lead->planOrSuggested()->name);

        // El horario ya no se ofrece y no se puede tomar dos veces
        $this->get('/')->assertDontSee('value="2026-10-20 10:00"', false);
        $this->postJson(route('demo.request'), ['name' => 'Otro', 'email' => 'otro@ejemplo.com', 'slot' => '2026-10-20 10:00'])
            ->assertStatus(422)->assertJsonValidationErrors('slot');
        $this->assertDatabaseMissing('demo_requests', ['email' => 'otro@ejemplo.com']);
    }

    public function test_sales_pages_render_for_superadmin(): void
    {
        $lead = $this->lead(['next_action' => 'Llamar', 'next_action_at' => now()->subHour()]);
        $lead->meetings()->create(['starts_at' => now()->addHours(3), 'duration_minutes' => 45]);
        $this->actingAs($this->admin);

        foreach (['tablero', 'lista', 'metricas'] as $view) {
            $this->get(route('saas.sales.leads', ['vista' => $view]))->assertOk()->assertSee('Prospectos');
        }
        $this->get(route('saas.sales.leads'))->assertSee('Red Sierra')->assertSee('Llamar');
        $this->get(route('saas.sales.leads.show', $lead))->assertOk()->assertSee('Red Sierra')->assertSee('Iniciar prueba');
        foreach (['mes', 'semana', 'lista'] as $view) {
            $this->get(route('saas.sales.agenda', ['vista' => $view]))->assertOk()->assertSee('Agenda de demos');
        }
        $this->get(route('saas.dashboard'))->assertOk()->assertSee('Demos de hoy')->assertSee('Seguimientos vencidos');
        $this->get('/saas/solicitudes-demo')->assertRedirect('/saas/ventas/prospectos');

        $ics = $this->get(route('saas.sales.meeting.ics', $lead->meetings()->first()));
        $ics->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('DTSTART:20261019T180000Z', $ics->getContent()); // 12:00 CDMX
    }

    public function test_board_moves_follow_the_stage_rules(): void
    {
        $lead = $this->lead();
        $c = Livewire::actingAs($this->admin)->test(Leads::class);

        $c->call('move', $lead->id, 'contacted');
        $this->assertSame('contacted', $lead->refresh()->status);
        $this->assertNotNull($lead->contacted_at);
        $this->assertSame('Etapa: Nuevo → Contactado', $lead->activities()->first()->body);

        // Demo agendada sin demo → abre la agenda en vez de cambiar la etapa
        $c->call('move', $lead->id, 'demo_scheduled')->assertDispatched('schedule-demo', leadId: $lead->id);
        $this->assertSame('contacted', $lead->refresh()->status);

        // En prueba sin WISP → lleva a crear el WISP de prueba
        $c->call('move', $lead->id, 'trial')->assertRedirect(route('saas.dashboard', ['prospecto' => $lead->id]));

        // Perdido pide motivo
        $c->call('move', $lead->id, 'lost')->assertSet('showLost', true)
            ->call('confirmLost')->assertHasErrors('lost.reason')
            ->set('lost.reason', 'price')->set('lost.note', 'Le pareció caro')->call('confirmLost')->assertHasNoErrors();
        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('price', $lead->lost_reason);
        $this->assertSame(1, $lead->max_stage);
    }

    public function test_manual_lead_needs_email_or_phone(): void
    {
        Livewire::actingAs($this->admin)->test(Leads::class)
            ->call('create')->set('form.name', 'Pedro')->set('form.email', '')->set('form.phone', '')
            ->call('save')->assertHasErrors(['form.email', 'form.phone'])
            ->set('form.phone', '4429876543')->set('form.company', 'Wifi Pedro')
            ->call('save')->assertHasNoErrors();

        $lead = DemoRequest::where('name', 'Pedro')->firstOrFail();
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame($this->admin->id, $lead->assigned_to);
    }

    public function test_meeting_form_schedules_notifies_and_detects_conflicts(): void
    {
        $lead = $this->lead();
        $other = $this->lead(['name' => 'Beto', 'email' => 'beto@ejemplo.com', 'company' => 'Otra Red']);

        Livewire::actingAs($this->admin)->test(MeetingForm::class)
            ->call('open', leadId: $lead->id)->assertSet('leadLocked', true)
            ->set('form.date', '2026-10-21')->set('form.time', '11:00')->set('form.meeting_url', 'https://meet.google.com/abc-defg-hij')
            ->call('save')->assertHasNoErrors()->assertDispatched('meeting-saved');

        $this->assertSame('demo_scheduled', $lead->refresh()->status);
        Mail::assertSent(DemoMeetingMail::class, fn ($m) => $m->hasTo('ana@ejemplo.com'));

        Livewire::actingAs($this->admin)->test(MeetingForm::class)
            ->call('open', leadId: $other->id)->set('form.date', '2026-10-21')->set('form.time', '11:30')
            ->call('save')->assertHasErrors('form.time');
        $this->assertSame(0, $other->meetings()->count());

        // Reprogramar avisa con otro correo
        $meeting = $lead->meetings()->first();
        Livewire::actingAs($this->admin)->test(MeetingForm::class)
            ->call('open', meetingId: $meeting->id)->set('form.time', '13:00')->call('save')->assertHasNoErrors();
        $this->assertSame('13:00', $meeting->refresh()->starts_at->format('H:i'));
        Mail::assertSent(DemoMeetingMail::class, fn ($m) => $m->kind === 'rescheduled');
    }

    public function test_lead_page_logs_activity_and_meeting_results(): void
    {
        $lead = $this->lead();
        $meeting = $lead->meetings()->create(['starts_at' => now()->addHour(), 'duration_minutes' => 45]);
        $lead->update(['status' => 'demo_scheduled']);

        $c = Livewire::actingAs($this->admin)->test(LeadShow::class, ['lead' => $lead])
            ->set('activity.type', 'call')->set('activity.body', 'Confirmó asistencia')->call('addActivity')->assertHasNoErrors()
            ->set('next.action', 'Enviar propuesta')->set('next.at', '2026-10-22T10:00')->call('saveNext')
            ->call('markMeeting', $meeting->id, 'done');

        $lead->refresh();
        $this->assertSame('demo_done', $lead->status);
        $this->assertSame('Enviar propuesta', $lead->next_action);
        $this->assertSame('done', $meeting->refresh()->status);

        $c->call('completeNext');
        $this->assertNull($lead->refresh()->next_action);
        $this->assertTrue($lead->activities()->where('body', 'Hecho: Enviar propuesta')->exists());
    }

    public function test_converting_a_lead_starts_a_trial_and_activation_wins_it(): void
    {
        $lead = $this->lead(['status' => 'demo_done']);

        $this->actingAs($this->admin)->get(route('saas.dashboard', ['prospecto' => $lead->id]))->assertOk()->assertSee('Datos tomados del prospecto');

        $c = Livewire::withQueryParams(['prospecto' => $lead->id])->actingAs($this->admin)->test(Dashboard::class)
            ->assertSet('showForm', true)->assertSet('form.name', 'Red Sierra')->assertSet('form.slug', 'red-sierra')
            ->assertSet('form.admin_email', 'ana@ejemplo.com')->assertSet('form.trial', true)
            ->set('form.admin_password', 'secreto123')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('saas.sales.leads.show', $lead));

        $tenant = Tenant::where('slug', 'red-sierra')->firstOrFail();
        $this->assertSame('trial', $tenant->status);
        $this->assertSame('2026-11-02', $tenant->trial_ends_at->toDateString());
        $this->assertSame('Pro', $tenant->saasPlan->name); // 100 a 500 clientes → Pro
        $lead->refresh();
        $this->assertSame('trial', $lead->status);
        $this->assertSame($tenant->id, $lead->tenant_id);

        $this->get(route('saas.sales.leads.show', $lead))->assertSee('Quedan 14 días de prueba')->assertSee('Activación');

        $c->call('editTenant', $tenant->id)->set('edit.status', 'active')->call('updateTenant')->assertHasNoErrors();
        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertNotNull($lead->won_at);
        $this->assertSame(1999.0, $lead->mrr());
    }

    public function test_reminders_go_out_once_for_demos_in_the_next_day(): void
    {
        $lead = $this->lead();
        $tomorrow = DemoMeeting::create(['demo_request_id' => $lead->id, 'starts_at' => now()->addHours(20), 'duration_minutes' => 45]);
        DemoMeeting::create(['demo_request_id' => $lead->id, 'starts_at' => now()->addDays(3), 'duration_minutes' => 45]);

        $this->artisan('saas:demo-reminders')->expectsOutputToContain('Recordatorios enviados: 1')->assertSuccessful();
        $this->artisan('saas:demo-reminders')->expectsOutputToContain('Recordatorios enviados: 0');

        $this->assertNotNull($tomorrow->refresh()->reminder_sent_at);
        Mail::assertSent(DemoMeetingMail::class, 1);
        Mail::assertSent(DemoMeetingMail::class, fn ($m) => $m->kind === 'reminder');
    }

    public function test_agenda_marks_results(): void
    {
        $lead = $this->lead(['status' => 'demo_scheduled']);
        $m = $lead->meetings()->create(['starts_at' => now()->addHours(2), 'duration_minutes' => 45]);

        Livewire::actingAs($this->admin)->test(Agenda::class)
            ->assertSee('Red Sierra')
            ->call('select', $m->id)->assertSet('showMeeting', true)
            ->call('markMeeting', $m->id, 'no_show')
            ->call('shift', 1)->assertSet('date', '2026-11-01');

        $this->assertSame('no_show', $m->refresh()->status);
        $this->assertSame('demo_scheduled', $lead->refresh()->status);
    }
}
