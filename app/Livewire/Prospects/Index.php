<?php

namespace App\Livewire\Prospects;

use App\Livewire\Concerns\FillsAddressFromLocation;
use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Prospect;
use App\Models\User;
use App\Models\Zone;
use App\Services\CoverageService;
use App\Services\ProspectService;
use App\Support\Catalog;
use App\Support\DemandAnalysis;
use App\Support\InputRules;
use App\Support\MapData;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Prospectos y demanda (secciones 19 y 20): registro con validación de cobertura,
 * vistas lista / tablero / mapa, concentración de demanda y acciones comerciales.
 */
#[Title('Prospectos')]
class Index extends Component
{
    use FillsAddressFromLocation, Toasts, WithTable;

    #[Url(as: 'vista', except: 'lista')]
    public string $view = 'lista';

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'fuente', except: '')]
    public string $filterSource = '';

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'asignado', except: '')]
    public string $filterAssigned = '';

    #[Url(as: 'cobertura', except: '')]
    public string $filterCoverage = '';

    protected array $sortable = ['name', 'created_at', 'distance_km', 'status', 'contacted_at'];

    // Formulario
    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public ?array $coverage = null;

    // Orden de instalación / conversión
    public bool $showOrder = false;

    public bool $showConvert = false;

    public ?int $actionId = null;

    public array $order = ['plan_id' => '', 'promotion_id' => '', 'technician_id' => '', 'notes' => ''];

    public array $convert = ['plan_id' => ''];

    public bool $mapDirty = false;

    public function mount(): void
    {
        $this->form = $this->blankForm();
    }

    protected function blankForm(): array
    {
        return [
            'name' => '', 'phone' => '', 'whatsapp' => '', 'email' => '', 'address' => '', 'lat' => null, 'lng' => null,
            'zone_id' => '', 'desired_plan_id' => '', 'source' => 'whatsapp', 'status' => 'nuevo', 'assigned_to' => '',
            'referral_code' => '', 'notes' => '', 'date' => now()->toDateString(),
        ];
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['lista', 'tablero', 'mapa'], true) ? $view : 'lista';
    }

    public function showAll(string $status): void
    {
        $this->filterStatus = $status;
        $this->view = 'lista';
        $this->resetPage();
    }

    /** Filtros: resetea página y marca el mapa para refrescar. */
    public function updated($property): void
    {
        if (str_starts_with($property, 'filter') || $property === 'search') {
            $this->resetPage();
            $this->mapDirty = true;
        }
    }

    public function clearFilters(): void
    {
        $this->reset('filterStatus', 'filterSource', 'filterZone', 'filterAssigned', 'filterCoverage', 'search');
        $this->resetPage();
        $this->mapDirty = true;
    }

    /** Cobertura en vivo al elegir ubicación. */
    public function updatedForm($value, $key): void
    {
        if (in_array($key, ['lat', 'lng'], true) && $this->form['lat'] && $this->form['lng']) {
            $cov = app(CoverageService::class)->check((float) $this->form['lat'], (float) $this->form['lng']);
            $this->coverage = [
                'ok' => (bool) $cov['has_coverage'], 'message' => $cov['message'], 'repeater' => $cov['repeater']?->code,
                'distance' => $cov['distance'], 'capacity_ok' => $cov['capacity_ok'],
            ];
            if (! $this->form['zone_id'] && $cov['repeater']?->zone_id) {
                $this->form['zone_id'] = (string) $cov['repeater']->zone_id;
            }
        }
    }

    // ------------------------------------------------------------ CRUD

    public function create(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->coverage = null;
        $this->form = $this->blankForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Prospect::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $p->id;
        $this->form = array_merge($this->blankForm(), array_map(fn ($v) => $v ?? '', $p->only([
            'name', 'phone', 'whatsapp', 'email', 'address', 'zone_id', 'desired_plan_id', 'source', 'status', 'assigned_to', 'referral_code', 'notes',
        ])), [
            'lat' => $p->lat ? (float) $p->lat : null, 'lng' => $p->lng ? (float) $p->lng : null,
            'date' => $p->created_at?->toDateString(),
        ]);
        $this->coverage = $p->lat ? [
            'ok' => (bool) $p->has_coverage, 'repeater' => $p->nearestRepeater?->code, 'distance' => $p->distance_km, 'capacity_ok' => true,
            'message' => $p->has_coverage ? 'Con cobertura desde '.$p->nearestRepeater?->code.' a '.$p->distance_km.' km' : 'Sin cobertura: repetidor más cercano '.$p->nearestRepeater?->code.' a '.$p->distance_km.' km',
        ] : null;
        $this->showForm = true;
    }

    /** El prospecto solo tiene un campo de dirección: se llena con la dirección completa. */
    protected function addressFields(): array
    {
        return ['address' => 'full'];
    }

    protected function rules(): array
    {
        $tid = tenant()->id;

        return [
            'form.name' => 'required|string|max:80|'.InputRules::NAME,
            'form.phone' => 'nullable|string|max:20|'.InputRules::PHONE,
            'form.whatsapp' => 'nullable|string|max:20|'.InputRules::PHONE,
            'form.email' => 'nullable|email|max:150',
            'form.address' => 'nullable|string|max:190|'.InputRules::TEXT,
            'form.lat' => 'nullable|numeric|between:-90,90',
            'form.lng' => 'nullable|numeric|between:-180,180',
            'form.zone_id' => ['nullable', Rule::exists('zones', 'id')->where('tenant_id', $tid)],
            'form.desired_plan_id' => ['nullable', Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'form.source' => ['required', Rule::in(array_keys(config('wisp.prospect_sources')))],
            'form.status' => ['required', Rule::in(array_keys(config('wisp.prospect_status')))],
            'form.assigned_to' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'form.referral_code' => 'nullable|string|max:30|'.InputRules::CODE,
            'form.notes' => 'nullable|string|max:2000',
            'form.date' => 'nullable|date|before_or_equal:today',
        ];
    }

    protected function validationAttributes(): array
    {
        return ['form.name' => 'nombre', 'form.email' => 'correo', 'form.date' => 'fecha', 'form.status' => 'estado', 'form.source' => 'fuente',
            'form.phone' => 'teléfono', 'form.whatsapp' => 'WhatsApp', 'form.address' => 'dirección', 'form.referral_code' => 'código de referido'];
    }

    public function save(ProspectService $service): void
    {
        $data = $this->validate()['form'];
        $date = $data['date'] ?? null;
        unset($data['date']);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['referral_code'] = $data['referral_code'] ? strtoupper(trim($data['referral_code'])) : null;
        if (! $data['phone'] && ! $data['whatsapp']) {
            $this->addError('form.phone', 'Captura al menos un teléfono o WhatsApp.');

            return;
        }

        $p = $this->editingId ? Prospect::findOrFail($this->editingId) : new Prospect;
        $locationChanged = ! $p->exists || (string) $p->lat !== (string) $data['lat'] || (string) $p->lng !== (string) $data['lng'];
        $p->fill($data);
        if (! $data['lat'] || ! $data['lng']) {
            $p->forceFill(['nearest_repeater_id' => null, 'distance_km' => null, 'has_coverage' => null]);
        } elseif ($locationChanged || $p->has_coverage === null || $p->isDirty('status')) {
            $service->applyCoverage($p);
        }
        if ($date && (! $p->exists || $p->created_at?->toDateString() !== $date)) {
            $p->created_at = Carbon::parse($date)->setTimeFrom(now());
        }
        $p->save();

        $this->showForm = false;
        $this->mapDirty = true;
        $msg = $this->editingId ? 'Prospecto actualizado' : 'Prospecto registrado';
        if ($p->lat) {
            $msg .= $p->has_coverage ? ' · con cobertura ('.$p->nearestRepeater?->code.')' : ' · sin cobertura';
        }
        $this->toast($msg, $p->lat && ! $p->has_coverage ? 'warning' : 'success');
    }

    public function delete(int $id): void
    {
        $p = Prospect::findOrFail($id);
        if ($p->installationOrders()->whereNotIn('status', ['cancelada'])->exists()) {
            $this->toast('El prospecto tiene órdenes de instalación; márcalo como descartado.', 'warning');

            return;
        }
        $p->delete();
        $this->showForm = false;
        $this->mapDirty = true;
        $this->toast('Prospecto eliminado');
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, config('wisp.prospect_status')), 422);
        $p = Prospect::findOrFail($id);
        if ($status === 'convertido' && ! $p->client_id) {
            $this->openConvert($id);

            return;
        }
        $p->update(['status' => $status] + ($status === 'contactado' && ! $p->contacted_at ? ['contacted_at' => now()] : []));
        $this->toast('Estado: '.Catalog::label('prospect_status', $status));
    }

    public function recheckCoverage(int $id, ProspectService $service): void
    {
        $p = Prospect::findOrFail($id);
        $cov = $service->applyCoverage($p);
        $p->save();
        $this->toast($cov ? $cov['message'] : 'El prospecto no tiene coordenadas', $cov && $cov['has_coverage'] ? 'success' : 'warning');
    }

    /** Registra el contacto (el enlace de WhatsApp se abre en el navegador). */
    public function contacted(int $id, ProspectService $service): void
    {
        $service->markContacted(Prospect::findOrFail($id));
        $this->toast('Contacto registrado');
    }

    // ------------------------------------------------------------ Orden de instalación / conversión

    public function openOrder(int $id): void
    {
        $this->authorize('installations.manage');
        $p = Prospect::findOrFail($id);
        $this->actionId = $p->id;
        $this->order = ['plan_id' => (string) ($p->desired_plan_id ?? ''), 'promotion_id' => '', 'technician_id' => '', 'notes' => ''];
        $this->resetValidation();
        $this->showOrder = true;
    }

    public function createOrder(ProspectService $service)
    {
        $this->authorize('installations.manage');
        $tid = tenant()->id;
        $this->validate([
            'order.plan_id' => ['required', Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'order.promotion_id' => ['nullable', Rule::exists('promotions', 'id')->where('tenant_id', $tid)],
            'order.technician_id' => ['nullable', Rule::exists('users', 'id')->where('tenant_id', $tid)],
            'order.notes' => 'nullable|string|max:1000',
        ], [], ['order.plan_id' => 'plan']);
        $p = Prospect::findOrFail($this->actionId);
        $order = $service->createOrder($p, array_map(fn ($v) => $v === '' ? null : $v, $this->order));
        session()->flash('success', "Orden {$order->folio} creada en etapa de cotización");

        return $this->redirectRoute('installations.show', $order, navigate: true);
    }

    public function openConvert(int $id): void
    {
        $this->authorize('clients.manage');
        $p = Prospect::findOrFail($id);
        $this->actionId = $p->id;
        $this->convert = ['plan_id' => (string) ($p->desired_plan_id ?? '')];
        $this->resetValidation();
        $this->showConvert = true;
    }

    public function convertToClient(ProspectService $service)
    {
        $this->authorize('clients.manage');
        $this->validate(['convert.plan_id' => ['required', Rule::exists('plans', 'id')->where('tenant_id', tenant()->id)]], [], ['convert.plan_id' => 'plan']);
        $p = Prospect::findOrFail($this->actionId);
        if (! $p->client_id && tenant()->exceedsLimit('clients', ['service_status' => 'instalacion_pendiente'])) {
            $this->addError('convert.plan_id', tenant()->limitMessage('clients'));

            return null;
        }
        $client = $service->convertToClient($p, ['plan_id' => (int) $this->convert['plan_id']]);
        session()->flash('success', "Prospecto convertido en cliente #{$client->code} (instalación pendiente)");

        return $this->redirectRoute('clients.show', $client, navigate: true);
    }

    // ------------------------------------------------------------ Datos

    protected function query()
    {
        return Prospect::query()
            ->with(['zone:id,name', 'desiredPlan:id,name,color', 'nearestRepeater:id,code', 'assignedTo:id,name'])
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('whatsapp', 'like', $like)
                    ->orWhere('address', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($this->filterStatus, fn ($q) => $this->filterStatus === 'abiertos' ? $q->whereIn('status', ProspectService::OPEN) : $q->where('status', $this->filterStatus))
            ->when($this->filterSource, fn ($q) => $q->where('source', $this->filterSource))
            ->when($this->filterZone, fn ($q) => $this->filterZone === 'none' ? $q->whereNull('zone_id') : $q->where('zone_id', $this->filterZone))
            ->when($this->filterAssigned, fn ($q) => $q->where('assigned_to', $this->filterAssigned))
            ->when($this->filterCoverage !== '', fn ($q) => match ($this->filterCoverage) {
                'si' => $q->where('has_coverage', true),
                'no' => $q->where('has_coverage', false),
                default => $q->whereNull('lat'),
            });
    }

    protected function mapData(): array
    {
        $prospects = $this->query()->whereNotNull('lat')->get();
        $service = app(ProspectService::class);
        $markers = $prospects->map(fn ($p) => [
            'lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'type' => 'prospect', 'layer' => 'prospects',
            'color' => match (true) {
                $p->status === 'convertido' => '#00A0A8',
                $p->status === 'descartado' => '#CBD5E1',
                (bool) $p->has_coverage => '#6048D0',
                default => '#E5484D',
            },
            'size' => 22,
            'popup' => '<b>'.e($p->name).'</b><br>'.Catalog::label('prospect_status', $p->status).' · '.($p->has_coverage ? 'Con cobertura' : 'Sin cobertura')
                .($p->distance_km !== null ? '<br>'.$p->nearestRepeater?->code.' a '.$p->distance_km.' km' : '')
                .($p->desiredPlan ? '<br>Plan deseado: '.e($p->desiredPlan->name) : '')
                .(($wa = $service->whatsappUrl($p)) ? '<br><a target="_blank" class="font-semibold text-success-600" href="'.e($wa).'">WhatsApp →</a>' : ''),
        ])->values()->all();

        return [
            'markers' => array_merge($markers, MapData::repeaters()),
            'circles' => MapData::coverageCircles(),
            'heat' => $prospects->whereNotIn('status', ['convertido', 'descartado'])->map(fn ($p) => [(float) $p->lat, (float) $p->lng, $p->has_coverage ? 0.5 : 0.9])->values()->all(),
            'fit' => true,
        ];
    }

    public function render()
    {
        $counts = Prospect::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $open = collect(ProspectService::OPEN)->sum(fn ($s) => $counts[$s] ?? 0);
        $stats = Prospect::query()->whereIn('status', ProspectService::OPEN)
            ->selectRaw('SUM(CASE WHEN has_coverage = 1 THEN 1 ELSE 0 END) covered, SUM(CASE WHEN has_coverage = 0 THEN 1 ELSE 0 END) uncovered')
            ->first();
        $recent = Prospect::query()->where('created_at', '>=', now()->subDays(30))->count();
        $converted30 = Prospect::query()->where('status', 'convertido')->where('converted_at', '>=', now()->subDays(30))->count();

        $demand = new DemandAnalysis;
        $data = [
            'counts' => $counts,
            'kpi' => [
                'open' => $open, 'covered' => (int) $stats->covered, 'uncovered' => (int) $stats->uncovered, 'recent' => $recent,
                'converted30' => $converted30, 'conversion' => ($counts->sum() ?: 0) ? round(($counts['convertido'] ?? 0) / $counts->sum() * 100, 1) : 0,
            ],
            'demandZones' => $demand->byZone(),
            'demandClusters' => array_slice($demand->clusters(), 0, 6),
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'plans' => Plan::where('active', true)->orderBy('price')->get(),
            'users' => User::forTenant()->active()->role(['admin', 'soporte', 'supervisor', 'tecnico', 'finanzas'])->orderBy('name')->pluck('name', 'id'),
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->pluck('name', 'id'),
            'promotions' => Promotion::query()->current()->orderBy('name')->get(),
            'service' => app(ProspectService::class),
        ];

        if ($this->view === 'lista') {
            $data['prospects'] = $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage);
        } elseif ($this->view === 'tablero') {
            $columns = ['nuevo', 'contactado', 'viable', 'sin_cobertura', 'instalacion_pendiente', 'convertido'];
            $data['columns'] = collect($columns)->mapWithKeys(fn ($s) => [$s => [
                'items' => (clone $this->query())->where('status', $s)->latest()->limit(30)->get(),
                'total' => (clone $this->query())->where('status', $s)->count(),
            ]]);
        } else {
            $data['map'] = $this->mapData();
            if ($this->mapDirty) {
                $this->dispatch('map-data-prospects-map', data: $data['map']);
            }
        }
        $this->mapDirty = false;

        return view('livewire.prospects.index', $data);
    }
}
