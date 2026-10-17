<?php

namespace App\Livewire\Saas\Sales;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Concerns\PlatformContext;
use App\Livewire\Saas\Sales\Concerns\HandlesStages;
use App\Livewire\Saas\Sales\Concerns\SalesContext;
use App\Models\DemoActivity;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use App\Models\SaasPlan;
use App\Models\Tenant;
use App\Services\DemoScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Ficha de un prospecto del CRM de ventas: datos del WISP, etapa, siguiente paso, historial,
 * demos y —una vez convertido— el avance de su prueba gratuita en la plataforma.
 */
class LeadShow extends Component
{
    use HandlesStages, PlatformContext, SalesContext, Toasts;

    public DemoRequest $lead;

    public bool $showEdit = false;

    public array $edit = [];

    public array $activity = ['type' => 'note', 'body' => ''];

    public array $next = ['action' => '', 'at' => ''];

    public function mount(DemoRequest $lead): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        $this->lead = $lead;
        $this->fillNext();
    }

    public function getTitle(): string
    {
        return $this->lead->company ?: $this->lead->name;
    }

    protected function fillNext(): void
    {
        $this->next = ['action' => (string) $this->lead->next_action, 'at' => $this->lead->next_action_at?->format('Y-m-d\TH:i') ?? ''];
    }

    #[On('meeting-saved')]
    public function refreshLead(): void
    {
        $this->lead->refresh();
        $this->refreshBadge();
    }

    public function openEdit(): void
    {
        $l = $this->lead;
        $this->resetValidation();
        $this->edit = [
            'name' => $l->name, 'email' => (string) $l->email, 'phone' => (string) $l->phone, 'company' => (string) $l->company,
            'location' => (string) $l->location, 'clients' => (string) $l->clients, 'clients_count' => (string) ($l->clients_count ?? ''),
            'current_software' => (string) $l->current_software, 'uses_mikrotik' => $l->uses_mikrotik === null ? '' : ($l->uses_mikrotik ? '1' : '0'),
            'source' => $l->source, 'assigned_to' => (string) ($l->assigned_to ?? ''),
            'saas_plan_id' => (string) ($l->saas_plan_id ?? ''), 'estimated_mrr' => (string) ($l->estimated_mrr ?? ''),
        ];
        $this->showEdit = true;
    }

    public function saveEdit(): void
    {
        $data = $this->validate([
            'edit.name' => 'required|string|max:120',
            'edit.email' => 'nullable|required_without:edit.phone|email|max:160',
            'edit.phone' => 'nullable|required_without:edit.email|string|max:30',
            'edit.company' => 'nullable|string|max:160',
            'edit.location' => 'nullable|string|max:120',
            'edit.clients' => ['nullable', Rule::in(array_keys(DemoRequest::CLIENT_RANGES))],
            'edit.clients_count' => 'nullable|integer|min:0|max:1000000',
            'edit.current_software' => 'nullable|string|max:80',
            'edit.uses_mikrotik' => 'nullable|in:0,1',
            'edit.source' => ['required', Rule::in(array_keys(DemoRequest::SOURCES))],
            'edit.assigned_to' => 'nullable|exists:users,id',
            'edit.saas_plan_id' => 'nullable|exists:saas_plans,id',
            'edit.estimated_mrr' => 'nullable|numeric|min:0|max:9999999',
        ], [
            'edit.email.required_without' => 'Captura al menos el correo o el teléfono.',
            'edit.phone.required_without' => 'Captura al menos el correo o el teléfono.',
        ], [
            'edit.name' => 'nombre', 'edit.email' => 'correo', 'edit.phone' => 'teléfono', 'edit.company' => 'WISP', 'edit.location' => 'ubicación',
            'edit.clients' => 'clientes', 'edit.clients_count' => 'número de clientes', 'edit.source' => 'origen', 'edit.assigned_to' => 'responsable',
            'edit.saas_plan_id' => 'plan', 'edit.estimated_mrr' => 'MRR estimado',
        ])['edit'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $before = $this->lead->assigned_to;
        $this->lead->update($data);
        if ($this->lead->assigned_to && $this->lead->assigned_to !== $before) {
            $this->lead->log('system', 'Asignado a '.$this->lead->assignee->name);
        }
        $this->showEdit = false;
        $this->toast('Datos actualizados');
    }

    public function addActivity(): void
    {
        $data = $this->validate([
            'activity.type' => ['required', Rule::in(array_keys(DemoActivity::TYPES))],
            'activity.body' => 'required|string|max:2000',
        ], [], ['activity.type' => 'tipo', 'activity.body' => 'detalle'])['activity'];
        $this->lead->log($data['type'], $data['body']);
        // Hablar con el prospecto cuenta como primer contacto
        if ($this->lead->status === 'new' && in_array($data['type'], ['call', 'whatsapp', 'email'], true)) {
            $this->lead->changeStage('contacted');
            $this->refreshBadge();
        }
        $this->activity = ['type' => $data['type'], 'body' => ''];
        $this->toast('Actividad registrada');
    }

    public function saveNext(): void
    {
        $data = $this->validate([
            'next.action' => 'nullable|required_with:next.at|string|max:190',
            'next.at' => 'nullable|date',
        ], [], ['next.action' => 'siguiente paso', 'next.at' => 'fecha'])['next'];
        $this->lead->update(['next_action' => $data['action'] ?: null, 'next_action_at' => $data['at'] ?: null]);
        $this->toast($data['action'] ? 'Siguiente paso guardado' : 'Siguiente paso eliminado');
    }

    public function completeNext(): void
    {
        if ($this->lead->next_action) {
            $this->lead->log('note', 'Hecho: '.$this->lead->next_action);
        }
        $this->lead->update(['next_action' => null, 'next_action_at' => null]);
        $this->fillNext();
        $this->toast('Listo. Define el siguiente paso para no perder el seguimiento.', 'info');
    }

    public function markMeeting(int $id, string $status): void
    {
        $m = $this->lead->meetings()->findOrFail($id);
        $m->markAs($status);
        $this->lead->refresh();
        $this->toast('Demo: '.mb_strtolower(DemoMeeting::STATUSES[$status]));
    }

    public function resendInvite(int $id, DemoScheduler $scheduler): void
    {
        $m = $this->lead->meetings()->findOrFail($id);
        if ($scheduler->send($m, 'confirmation')) {
            $this->lead->log('email', 'Confirmación de la demo reenviada a '.$this->lead->email);
            $this->toast('Confirmación reenviada a '.$this->lead->email);
        } else {
            $this->toast('No se pudo enviar: revisa que el prospecto tenga correo y la configuración de correo.', 'error');
        }
    }

    public function delete()
    {
        $name = $this->lead->company ?: $this->lead->name;
        $this->platformLog('demo_request_deleted', "eliminó el prospecto {$name} ({$this->lead->email})");
        $this->lead->delete();
        $this->toast('Prospecto eliminado', 'warning');

        return $this->redirectRoute('saas.sales.leads', navigate: true);
    }

    /** Avance de la prueba: uso real del WISP creado a partir del prospecto. */
    protected function trial(?Tenant $t): ?array
    {
        if (! $t) {
            return null;
        }
        $clients = $t->usage('clients');
        $devices = DB::table('network_devices')->whereNull('deleted_at')->where('tenant_id', $t->id)->count();
        $online = DB::table('network_devices')->whereNull('deleted_at')->where('tenant_id', $t->id)->where('status', 'online')->count();
        $payments = DB::table('payments')->where('tenant_id', $t->id)->where('status', 'completed')->selectRaw('COUNT(*) n, MAX(paid_at) last_at')->first();
        $users = DB::table('users')->where('tenant_id', $t->id)->where('active', true)->count();
        $steps = [
            ['Clientes cargados', $clients > 0, number_format($clients)],
            ['Equipo MikroTik conectado', $online > 0, $devices ? "{$online} de {$devices} en línea" : 'Sin equipos'],
            ['Primer cobro registrado', (int) $payments->n > 0, number_format((int) $payments->n).' pagos'],
            ['Equipo invitado', $users > 1, $users.' usuarios'],
        ];

        return [
            'tenant' => $t,
            'days_left' => $t->status === 'trial' && $t->trial_ends_at ? (int) now()->startOfDay()->diffInDays($t->trial_ends_at, false) : null,
            'steps' => $steps,
            'score' => (int) round(collect($steps)->where(1, true)->count() / count($steps) * 100),
            'last_payment' => $payments->last_at,
        ];
    }

    public function render()
    {
        $this->lead->load(['saasPlan', 'assignee', 'tenant.saasPlan']);

        return view('livewire.saas.sales.lead-show', [
            'activities' => $this->lead->activities()->with('user')->limit(100)->get(),
            'meetings' => $this->lead->meetings()->with('assignee')->get(),
            'suggested' => DemoRequest::suggestedPlan($this->lead->clientsEstimate()),
            'plans' => SaasPlan::orderBy('sort')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.money($p->price_monthly, false).'/mes'])->all(),
            'users' => $this->salesUsers(),
            'trial' => $this->trial($this->lead->tenant),
        ])->title($this->getTitle());
    }
}
