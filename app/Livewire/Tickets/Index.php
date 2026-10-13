<?php

namespace App\Livewire\Tickets;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Client;
use App\Models\Repeater;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Zone;
use App\Services\ReportExporter;
use App\Services\TicketService;
use App\Support\Catalog;
use App\Support\InputRules;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tickets de soporte (sección 21): listado y tablero kanban por estado, filtros,
 * KPIs de SLA, alta de tickets y asignación masiva.
 */
#[Title('Tickets')]
class Index extends Component
{
    use Toasts, WithTable;

    public const BOARD = ['abierto', 'asignado', 'en_proceso', 'en_espera', 'resuelto'];

    #[Url(as: 'vista', except: 'lista')]
    public string $view = 'lista';

    #[Url(as: 'estado', except: 'open')]
    public string $filterStatus = 'open';

    #[Url(as: 'prioridad', except: '')]
    public string $filterPriority = '';

    #[Url(as: 'categoria', except: '')]
    public string $filterCategory = '';

    #[Url(as: 'tecnico', except: '')]
    public string $filterTechnician = '';

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'sla', except: '')]
    public string $filterSla = '';

    #[Url(as: 'origen', except: '')]
    public string $filterSource = '';

    protected array $sortable = ['folio', 'priority', 'status', 'sla_due_at', 'created_at'];

    public array $selected = [];

    public string $bulkTechnician = '';

    // Nuevo ticket
    public bool $showCreate = false;

    public string $clientSearch = '';

    public array $form = [];

    // Resolver desde el tablero
    public bool $showResolve = false;

    public ?int $resolveId = null;

    public string $resolveNote = '';

    public function mount(): void
    {
        $this->resetForm();
        if (request()->boolean('nuevo') && auth()->user()->can('tickets.manage')) {
            $this->showCreate = true;
        }
    }

    protected function resetForm(): void
    {
        $this->form = ['client_id' => null, 'repeater_id' => '', 'category' => 'sin_internet', 'priority' => 'media', 'technician_id' => '', 'subject' => '', 'description' => '', 'source' => 'telefono'];
        $this->clientSearch = '';
    }

    public function clearFilters(): void
    {
        $this->reset('filterPriority', 'filterCategory', 'filterTechnician', 'filterZone', 'filterSla', 'filterSource', 'search');
        $this->filterStatus = 'open';
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['lista', 'tablero'], true) ? $view : 'lista';
        $this->selected = [];
    }

    /** Consulta base con todos los filtros excepto el estado. */
    protected function baseQuery(): Builder
    {
        $now = now()->toDateTimeString();

        return Ticket::query()
            ->when(trim($this->search), function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('folio', 'like', $term)->orWhere('subject', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->search(trim($this->search))));
            })
            ->when($this->filterPriority, fn ($q) => $q->where('priority', $this->filterPriority))
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterTechnician === 'none', fn ($q) => $q->whereNull('technician_id'))
            ->when($this->filterTechnician && $this->filterTechnician !== 'none', fn ($q) => $q->where('technician_id', $this->filterTechnician))
            ->when($this->filterSource, fn ($q) => $q->where('source', $this->filterSource))
            ->when($this->filterZone, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('client', fn ($c) => $c->where('zone_id', $this->filterZone))
                ->orWhere(fn ($x) => $x->whereNull('client_id')->whereHas('repeater', fn ($r) => $r->where('zone_id', $this->filterZone)))))
            ->when($this->filterSla === 'breached', fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($o) => $o->whereIn('status', Ticket::OPEN_STATUSES)->where('sla_due_at', '<', $now))
                ->orWhere(fn ($o) => $o->whereNotIn('status', Ticket::OPEN_STATUSES)->where('sla_breached', true))))
            ->when($this->filterSla === 'risk', fn ($q) => $q->whereIn('status', Ticket::OPEN_STATUSES)->where('sla_due_at', '>=', $now)
                ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, ?) >= 0.75 * TIMESTAMPDIFF(SECOND, created_at, sla_due_at)', [$now]))
            ->when($this->filterSla === 'ok', fn ($q) => $q->whereIn('status', Ticket::OPEN_STATUSES)->where(fn ($w) => $w->whereNull('sla_due_at')
                ->orWhere(fn ($x) => $x->where('sla_due_at', '>=', $now)
                    ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, ?) < 0.75 * TIMESTAMPDIFF(SECOND, created_at, sla_due_at)', [$now]))));
    }

    protected function query(): Builder
    {
        return $this->baseQuery()
            ->with(['client:id,code,first_name,last_name,zone_id', 'client.zone:id,name', 'technician:id,name', 'repeater:id,code'])
            ->when($this->filterStatus === 'open', fn ($q) => $q->open())
            ->when($this->filterStatus && $this->filterStatus !== 'open' && $this->filterStatus !== 'all', fn ($q) => $q->where('status', $this->filterStatus));
    }

    // ------------------------------------------------------------ Acciones

    public function updatedClientSearch(): void
    {
        $this->form['client_id'] = null;
    }

    public function selectClient(int $id): void
    {
        $c = Client::findOrFail($id);
        $this->form['client_id'] = $c->id;
        $this->form['repeater_id'] = (string) ($c->repeater_id ?? '');
        $this->clientSearch = $c->full_name.' · #'.$c->code;
    }

    public function create(TicketService $tickets)
    {
        $this->authorize('tickets.manage');
        $this->validate([
            'form.client_id' => 'nullable|integer|required_without:form.repeater_id',
            'form.repeater_id' => 'nullable',
            'form.category' => 'required|in:'.implode(',', array_keys(config('wisp.ticket_categories'))),
            'form.priority' => 'required|in:'.implode(',', array_keys(config('wisp.ticket_priorities'))),
            'form.technician_id' => 'nullable',
            'form.subject' => 'nullable|string|max:190|'.InputRules::TEXT,
            'form.description' => 'nullable|string|max:3000',
            'form.source' => 'required|in:admin,cliente,whatsapp,telefono,automatico',
        ], ['form.client_id.required_without' => 'Selecciona un cliente (o un repetidor para fallas de infraestructura).'],
            ['form.client_id' => 'cliente', 'form.category' => 'categoría', 'form.priority' => 'prioridad', 'form.subject' => 'asunto', 'form.description' => 'descripción']);

        $client = $this->form['client_id'] ? Client::findOrFail($this->form['client_id']) : null;
        $tech = $this->form['technician_id'] ? User::forTenant()->active()->findOrFail($this->form['technician_id']) : null;
        $data = [
            'client_id' => $client?->id,
            'repeater_id' => $this->form['repeater_id'] ?: $client?->repeater_id,
            'category' => $this->form['category'],
            'priority' => $this->form['priority'],
            'subject' => $this->form['subject'] ?: config('wisp.ticket_categories.'.$this->form['category']),
            'description' => $this->form['description'] ?: null,
            'source' => $this->form['source'],
            'technician_id' => $tech?->id,
        ];
        $ticket = $tickets->create($data);
        if ($tech) {
            $ticket->addComment("Asignado a {$tech->name}", 'assignment', false, ['technician_id' => $tech->id]);
            if ($ticket->client) {
                app(\App\Services\Messaging\MessageService::class)->notify($ticket->client, 'ticket_assigned', ['folio' => $ticket->folio, 'tecnico' => $tech->name]);
            }
        }
        $this->showCreate = false;
        $this->resetForm();
        $this->toast("Ticket {$ticket->folio} creado");

        return $this->redirectRoute('tickets.show', $ticket, navigate: true);
    }

    public function bulkAssign(TicketService $tickets): void
    {
        $this->authorize('tickets.manage');
        if (! $this->selected || ! $this->bulkTechnician) {
            $this->toast('Selecciona tickets y un técnico', 'warning');

            return;
        }
        $tech = User::forTenant()->active()->findOrFail($this->bulkTechnician);
        $n = 0;
        foreach (Ticket::query()->whereIn('id', $this->selected)->get() as $t) {
            if ((int) $t->technician_id !== $tech->id && $t->isOpen()) {
                $tickets->assign($t, $tech);
                $n++;
            }
        }
        $this->selected = [];
        $this->bulkTechnician = '';
        $this->toast("$n ticket(s) asignados a {$tech->name}");
    }

    public function toggleAllOnPage(array $ids): void
    {
        $ids = array_map('strval', $ids);
        $this->selected = count(array_intersect($ids, $this->selected)) === count($ids)
            ? array_values(array_diff($this->selected, $ids))
            : array_values(array_unique(array_merge($this->selected, $ids)));
    }

    /** Mover un ticket en el tablero. */
    public function moveTicket(int $id, string $status, TicketService $tickets): void
    {
        $this->authorize('tickets.manage');
        if (! in_array($status, self::BOARD, true)) {
            return;
        }
        $t = Ticket::findOrFail($id);
        if ($t->status === $status) {
            return;
        }
        if ($status === 'resuelto') {
            $this->resolveId = $t->id;
            $this->resolveNote = '';
            $this->showResolve = true;

            return;
        }
        if ($status === 'asignado' && ! $t->technician_id) {
            $this->toast("{$t->folio}: asigna un técnico desde el detalle del ticket", 'warning');

            return;
        }
        $tickets->changeStatus($t, $status);
        $this->toast("{$t->folio} → ".Catalog::label('ticket_status', $status));
    }

    public function resolve(TicketService $tickets): void
    {
        $this->authorize('tickets.manage');
        $this->validate(['resolveNote' => 'required|string|min:5|max:190'], [], ['resolveNote' => 'resolución']);
        $t = Ticket::findOrFail($this->resolveId);
        $tickets->changeStatus($t, 'resuelto', $this->resolveNote);
        $this->showResolve = false;
        $this->reset('resolveId', 'resolveNote');
        $this->toast("{$t->folio} resuelto");
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->applySort($this->query(), 'created_at', 'desc')->limit(5000)->get();

        return app(ReportExporter::class)->download('tickets', $format, [
            'Folio' => 'folio', 'Fecha' => 'created_at', 'Cliente' => fn ($t) => $t->client?->full_name ?? ($t->repeater ? 'Repetidor '.$t->repeater->code : ''),
            'Zona' => 'client.zone.name', 'Categoría' => fn ($t) => Catalog::label('ticket_categories', $t->category),
            'Asunto' => 'subject', 'Prioridad' => fn ($t) => Catalog::label('ticket_priorities', $t->priority),
            'Estado' => fn ($t) => Catalog::label('ticket_status', $t->status), 'Técnico' => 'technician.name',
            'Origen' => fn ($t) => $this->sources()[$t->source] ?? $t->source,
            'Vence SLA' => 'sla_due_at', 'SLA' => fn ($t) => ['ok' => 'En tiempo', 'risk' => 'En riesgo', 'breached' => 'Vencido', 'done' => 'Cumplido'][$t->slaState()],
            'Resuelto' => 'resolved_at', 'Resolución' => 'resolution',
        ], $rows, 'Reporte de tickets');
    }

    public function sources(): array
    {
        return ['admin' => 'Administración', 'cliente' => 'App / portal cliente', 'whatsapp' => 'WhatsApp', 'telefono' => 'Teléfono', 'automatico' => 'Automático'];
    }

    protected function kpis(): array
    {
        $now = now()->toDateTimeString();
        $open = Ticket::query()->open();
        $month = Ticket::query()->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->startOfMonth());
        $resolvedCount = (clone $month)->count();
        $avgMin = (float) (clone $month)->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) m')->value('m');
        $inSla = (clone $month)->where('sla_breached', false)->count();

        return [
            'open' => (clone $open)->count(),
            'critical' => (clone $open)->where('priority', 'critica')->count(),
            'overdue' => (clone $open)->where('sla_due_at', '<', $now)->count(),
            'risk' => (clone $open)->where('sla_due_at', '>=', $now)->whereRaw('TIMESTAMPDIFF(SECOND, created_at, ?) >= 0.75 * TIMESTAMPDIFF(SECOND, created_at, sla_due_at)', [$now])->count(),
            'unassigned' => (clone $open)->whereNull('technician_id')->count(),
            'avg_resolution' => $avgMin,
            'resolved_month' => $resolvedCount,
            'sla_pct' => $resolvedCount ? round($inSla / $resolvedCount * 100, 1) : null,
        ];
    }

    public static function duration(?float $minutes): string
    {
        if (! $minutes) {
            return '—';
        }
        $h = intdiv((int) round($minutes), 60);
        $m = (int) round($minutes) % 60;

        return $h >= 24 ? intdiv($h, 24).'d '.($h % 24).'h' : ($h ? "{$h}h {$m}m" : "{$m} min");
    }

    public function render()
    {
        $data = [
            'kpi' => $this->kpis(),
            'counts' => Ticket::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'technicians' => User::forTenant()->active()->role('tecnico')->orderBy('name')->pluck('name', 'id'),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'repeaters' => Repeater::orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]),
            'clientResults' => $this->showCreate && ! $this->form['client_id'] && mb_strlen(trim($this->clientSearch)) >= 2
                ? Client::query()->search($this->clientSearch)->with('zone:id,name')->limit(8)->get(['id', 'code', 'first_name', 'last_name', 'address', 'zone_id', 'service_status'])
                : collect(),
        ];

        if ($this->view === 'tablero') {
            $board = [];
            foreach (self::BOARD as $status) {
                $q = $this->baseQuery()->where('status', $status)
                    ->with(['client:id,code,first_name,last_name', 'technician:id,name', 'repeater:id,code']);
                if ($status === 'resuelto') {
                    $q->where('resolved_at', '>=', now()->subDays(3));
                }
                $board[$status] = [
                    'total' => (clone $q)->count(),
                    'items' => $q->orderByRaw("FIELD(priority, 'critica', 'alta', 'media', 'baja')")->orderBy('sla_due_at')->limit(40)->get(),
                ];
            }
            $data['board'] = $board;
        } else {
            $data['tickets'] = $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage);
        }

        return view('livewire.tickets.index', $data);
    }
}
