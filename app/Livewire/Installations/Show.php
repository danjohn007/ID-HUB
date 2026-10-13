<?php

namespace App\Livewire\Installations;

use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Repeater;
use App\Models\User;
use App\Services\CoverageService;
use App\Services\InstallationService;
use App\Services\ProspectService;
use App\Support\InputRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Orden de instalación (sección 40). Accesible para installations.manage o para el
 * técnico asignado (field.work), con vista cómoda en celular.
 */
class Show extends Component
{
    use Toasts, WithFileUploads;

    public InstallationOrder $order;

    // Cotización
    public array $items = [];

    public array $quote = ['plan_id' => '', 'promotion_id' => '', 'repeater_id' => '', 'notes' => ''];

    // Programación
    public array $schedule = ['date' => '', 'time' => '10:00', 'technician_id' => ''];

    // Instalación
    public array $measures = ['signal_dbm' => '', 'speed_down' => '', 'speed_up' => ''];

    public $photo;

    public string $photoKind = 'instalacion';

    public ?string $signature = null;

    public string $signedBy = '';

    // Activación
    public bool $chargeInstallation = true;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public ?array $coverage = null;

    public function mount(InstallationOrder $order): void
    {
        $this->order = $order;
        abort_unless($this->canManage() || $this->isAssignedTech(), 403);
        $this->fillState();
    }

    protected function fillState(): void
    {
        $o = $this->order;
        $this->items = collect($o->quote_items ?? [])->map(fn ($i) => ['concepto' => (string) ($i['concepto'] ?? ''), 'importe' => (string) ($i['importe'] ?? 0)])->values()->all();
        $this->quote = [
            'plan_id' => (string) ($o->plan_id ?? ''), 'promotion_id' => (string) ($o->promotion_id ?? ''),
            'repeater_id' => (string) ($o->repeater_id ?? $o->prospect?->nearest_repeater_id ?? ''), 'notes' => (string) $o->notes,
        ];
        $this->schedule = [
            'date' => $o->scheduled_at?->toDateString() ?? now()->addDay()->toDateString(),
            'time' => $o->scheduled_at?->format('H:i') ?? '10:00',
            'technician_id' => (string) ($o->technician_id ?? ''),
        ];
        $this->measures = ['signal_dbm' => (string) ($o->signal_dbm ?? ''), 'speed_down' => (string) ($o->speed_down ?? ''), 'speed_up' => (string) ($o->speed_up ?? '')];
        $this->signedBy = (string) ($o->signed_by_name ?: $o->customerName());
        $this->chargeInstallation = (float) $o->quote_amount > 0;
    }

    protected function canManage(): bool
    {
        return auth()->user()->can('installations.manage');
    }

    protected function isAssignedTech(): bool
    {
        return auth()->user()->can('field.work') && (int) $this->order->technician_id === (int) auth()->id();
    }

    protected function ensureManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    protected function ensureField(): void
    {
        abort_unless($this->canManage() || $this->isAssignedTech(), 403);
        abort_if(in_array($this->order->status, ['activada', 'cancelada'], true), 422, 'La orden ya está cerrada.');
    }

    // ------------------------------------------------------------ Cobertura y cotización

    public function checkCoverage(ProspectService $prospects): void
    {
        $this->ensureManage();
        if ($this->order->prospect && $this->order->prospect->lat) {
            $p = $this->order->prospect;
            $cov = $prospects->applyCoverage($p);
            $p->save();
        } elseif ($c = $this->order->client) {
            $cov = $c->lat ? app(CoverageService::class)->check((float) $c->lat, (float) $c->lng) : null;
        }
        if (empty($cov)) {
            $this->toast('Sin coordenadas para validar cobertura', 'warning');

            return;
        }
        if ($cov['repeater'] && ! $this->order->repeater_id) {
            $this->order->update(['repeater_id' => $cov['repeater']->id]);
            $this->quote['repeater_id'] = (string) $cov['repeater']->id;
        }
        $this->toast($cov['message'], $cov['has_coverage'] ? 'success' : 'warning');
    }

    public function addItem(): void
    {
        $this->items[] = ['concepto' => '', 'importe' => '0'];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    /** Recalcula partidas sugeridas con plan + promoción. */
    public function suggestQuote(ProspectService $prospects): void
    {
        $plan = Plan::find($this->quote['plan_id']);
        $promo = $this->quote['promotion_id'] ? Promotion::find($this->quote['promotion_id']) : null;
        $this->items = collect($prospects->defaultQuote($plan, $promo))->map(fn ($i) => ['concepto' => $i['concepto'], 'importe' => (string) $i['importe']])->all();
    }

    public function saveQuote(): void
    {
        $this->ensureManage();
        $tid = tenant()->id;
        $this->validate([
            'items' => 'array|max:20',
            'items.*.concepto' => 'required|string|max:120|'.InputRules::TEXT,
            'items.*.importe' => 'required|numeric|between:-100000,100000',
            'quote.plan_id' => ['required', Rule::exists('plans', 'id')->where('tenant_id', $tid)],
            'quote.promotion_id' => ['nullable', Rule::exists('promotions', 'id')->where('tenant_id', $tid)],
            'quote.repeater_id' => ['nullable', Rule::exists('repeaters', 'id')->where('tenant_id', $tid)],
            'quote.notes' => 'nullable|string|max:2000',
        ], [], ['items.*.concepto' => 'concepto', 'items.*.importe' => 'importe', 'quote.plan_id' => 'plan']);

        $items = collect($this->items)->map(fn ($i) => ['concepto' => trim($i['concepto']), 'importe' => round((float) $i['importe'], 2)])->values()->all();
        $this->order->update([
            'quote_items' => $items,
            'quote_amount' => max(0, round(collect($items)->sum('importe'), 2)),
            'plan_id' => $this->quote['plan_id'],
            'promotion_id' => $this->quote['promotion_id'] ?: null,
            'repeater_id' => $this->quote['repeater_id'] ?: null,
            'notes' => $this->quote['notes'] ?: null,
        ]);
        $this->chargeInstallation = (float) $this->order->quote_amount > 0;
        $this->toast('Cotización guardada · '.money($this->order->quote_amount));
    }

    public function accept(InstallationService $service): void
    {
        $this->ensureManage();
        $this->saveQuote();
        $service->accept($this->order);
        $this->toast('Cotización aceptada por el cliente');
    }

    public function saveSchedule(InstallationService $service): void
    {
        $this->ensureManage();
        $this->validate([
            'schedule.date' => 'required|date',
            'schedule.time' => 'required|date_format:H:i',
            'schedule.technician_id' => ['required', Rule::exists('users', 'id')->where('tenant_id', tenant()->id)],
        ], [], ['schedule.date' => 'fecha', 'schedule.time' => 'hora', 'schedule.technician_id' => 'técnico']);
        $service->schedule($this->order, $this->schedule['date'].' '.$this->schedule['time'].':00', (int) $this->schedule['technician_id']);
        $this->toast('Instalación programada para el '.$this->order->fresh()->scheduled_at->format('d/m/Y H:i'));
    }

    // ------------------------------------------------------------ Instalación (técnico)

    public function start(InstallationService $service): void
    {
        $this->ensureField();
        $service->start($this->order);
        $this->toast('Instalación iniciada');
    }

    public function toggleCheck(string $key, InstallationService $service): void
    {
        $this->ensureField();
        abort_unless(array_key_exists($key, config('wisp.installation_checklist')), 422);
        if (in_array($key, ['fotografia', 'firma'], true)) {
            $this->toast($key === 'firma' ? 'Se marca al guardar la firma del cliente' : 'Se marca al subir una fotografía', 'info');

            return;
        }
        $service->toggleChecklist($this->order, $key, ! ($this->order->checklist[$key] ?? false));
    }

    public function saveMeasures(): void
    {
        $this->ensureField();
        $this->validate([
            'measures.signal_dbm' => 'nullable|numeric|between:-120,0',
            'measures.speed_down' => 'nullable|numeric|between:0,10000',
            'measures.speed_up' => 'nullable|numeric|between:0,10000',
        ], [], ['measures.signal_dbm' => 'señal', 'measures.speed_down' => 'bajada', 'measures.speed_up' => 'subida']);
        $m = array_map(fn ($v) => $v === '' ? null : $v, $this->measures);
        $list = $this->order->checklist ?? [];
        if ($m['signal_dbm'] !== null) {
            $list['senal'] = true;
        }
        if ($m['speed_down'] !== null) {
            $list['velocidad'] = true;
        }
        $this->order->update($m + ['checklist' => $list]);
        $this->toast('Mediciones guardadas');
    }

    public function uploadPhoto(): void
    {
        $this->ensureField();
        $this->validate(['photo' => 'required|image|max:10240'], [], ['photo' => 'fotografía']);
        $path = $this->photo->store('evidences/'.$this->order->tenant_id.'/installations', 'public');
        $this->order->attachments()->create([
            'tenant_id' => $this->order->tenant_id, 'kind' => array_key_exists($this->photoKind, config('wisp.evidence_kinds')) ? $this->photoKind : 'instalacion',
            'path' => $path, 'original_name' => $this->photo->getClientOriginalName(), 'mime' => $this->photo->getMimeType(),
            'taken_at' => now(), 'uploaded_by' => auth()->id(),
        ]);
        $list = $this->order->checklist ?? [];
        $list['fotografia'] = true;
        $this->order->update(['checklist' => $list]);
        $this->reset('photo');
        $this->toast('Fotografía agregada');
    }

    public function deletePhoto(int $id): void
    {
        $this->ensureField();
        $a = $this->order->attachments()->findOrFail($id);
        Storage::disk('public')->delete($a->path);
        $a->delete();
        $this->toast('Fotografía eliminada', 'warning');
    }

    public function saveSignature(InstallationService $service): void
    {
        $this->ensureField();
        $this->validate([
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:3000000'],
            'signedBy' => 'required|string|max:150|'.InputRules::NAME,
        ], ['signature.required' => 'Dibuja la firma del cliente.'], ['signedBy' => 'nombre de quien firma']);
        $service->saveSignature($this->order, $this->signature, $this->signedBy);
        $this->signature = null;
        $this->toast('Firma guardada');
    }

    public function complete(InstallationService $service): void
    {
        $this->ensureField();
        $missing = collect(config('wisp.installation_checklist'))->keys()->reject(fn ($k) => $this->order->checklist[$k] ?? false);
        if ($missing->isNotEmpty()) {
            $this->toast('Faltan puntos del checklist: '.$missing->map(fn ($k) => config("wisp.installation_checklist.$k"))->implode(', '), 'error');

            return;
        }
        $service->complete($this->order);
        $this->toast('Instalación finalizada. Lista para activación.');
    }

    // ------------------------------------------------------------ Activación

    public function activate(InstallationService $service)
    {
        $this->ensureManage();
        abort_unless($this->order->status === 'instalada', 422);
        if (! $this->order->plan_id) {
            $this->toast('Asigna un plan en la cotización antes de activar', 'error');

            return null;
        }
        // Sin cliente aún (ni prospecto ya convertido) = se creará uno al activar
        $client = $this->order->client ?? Client::find($this->order->prospect?->client_id);
        if (tenant()->exceedsLimit('clients', ['service_status' => 'activo'], $client?->only('service_status'))) {
            $this->toast(tenant()->limitMessage('clients'), 'error');

            return null;
        }
        $result = $service->activate($this->order, $this->chargeInstallation);
        $this->order->refresh();
        $msg = "Cliente #{$result['client']->code} activo. ".$result['event']->network_result
            .($result['charged'] > 0 ? ' · Cargo de instalación '.money($result['charged']) : '');
        $this->toast($msg, $result['event']->network_applied ? 'success' : 'warning');

        return null;
    }

    public function cancel(InstallationService $service): void
    {
        $this->ensureManage();
        $this->validate(['cancelReason' => 'required|string|min:3|max:190'], [], ['cancelReason' => 'motivo']);
        $service->cancel($this->order, $this->cancelReason);
        $this->showCancel = false;
        $this->toast('Orden cancelada', 'warning');
    }

    public function render(InstallationService $service)
    {
        $o = $this->order->load(['prospect.nearestRepeater', 'prospect.desiredPlan', 'client', 'plan', 'repeater', 'technician', 'promotion', 'attachments']);
        $coords = $service->coordinates($o);
        $repeater = $o->repeater ?? $o->prospect?->nearestRepeater;
        $markers = [];
        $lines = [];
        if ($coords) {
            $markers[] = ['lat' => $coords['lat'], 'lng' => $coords['lng'], 'type' => $o->client ? 'client' : 'prospect', 'color' => '#E5484D', 'size' => 30, 'popup' => e($o->customerName())];
        }
        if ($repeater?->lat) {
            $markers[] = ['lat' => (float) $repeater->lat, 'lng' => (float) $repeater->lng, 'type' => 'repeater', 'status' => $repeater->status, 'popup' => e($repeater->code.' · '.$repeater->name)];
            if ($coords) {
                $lines[] = ['points' => [[$coords['lat'], $coords['lng']], [(float) $repeater->lat, (float) $repeater->lng]], 'dash' => true, 'weight' => 2];
            }
        }

        return view('livewire.installations.show', [
            'o' => $o,
            'step' => $service->stepIndex($o),
            'steps' => InstallationService::STEPS,
            'canManage' => $this->canManage(),
            'coords' => $coords,
            'repeater' => $repeater,
            'distance' => $coords && $repeater?->lat ? round(haversine_km($coords['lat'], $coords['lng'], (float) $repeater->lat, (float) $repeater->lng), 2) : null,
            'markers' => $markers,
            'lines' => $lines,
            'photos' => $o->attachments->where('kind', '!=', 'firma')->values(),
            'phone' => $o->client?->whatsapp ?? $o->client?->phone ?? $o->prospect?->whatsapp ?? $o->prospect?->phone,
            'plans' => Plan::where('active', true)->orderBy('price')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.$p->speedLabel().' · '.money($p->price)]),
            'promotions' => Promotion::query()->current()->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.$p->valueLabel()]),
            'repeaters' => Repeater::withLoad()->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => "{$r->code} · {$r->name} ({$r->utilization()}%)"]),
            'technicians' => User::forTenant()->active()->role(['tecnico', 'supervisor'])->orderBy('name')->pluck('name', 'id'),
            'logs' => AuditLog::query()->where('auditable_type', 'InstallationOrder')->where('auditable_id', $o->id)->latest('created_at')->limit(12)->get(),
        ])->title('Orden '.$o->folio);
    }
}
