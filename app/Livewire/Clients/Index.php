<?php

namespace App\Livewire\Clients;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\Zone;
use App\Services\ReportExporter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Clientes')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    #[Url(as: 'zona', except: '')]
    public string $filterZone = '';

    #[Url(as: 'plan', except: '')]
    public string $filterPlan = '';

    #[Url(as: 'repetidor', except: '')]
    public string $filterRepeater = '';

    #[Url(as: 'riesgo', except: '')]
    public string $filterRisk = '';

    protected array $sortable = ['code', 'first_name', 'balance', 'installation_date', 'next_due_date', 'risk_score'];

    public function clearFilters(): void
    {
        $this->reset('filterStatus', 'filterZone', 'filterPlan', 'filterRepeater', 'filterRisk', 'search');
        $this->resetPage();
    }

    protected function query()
    {
        return Client::query()
            ->with(['plan:id,name,download_mbps,color', 'zone:id,name', 'repeater:id,code'])
            ->search($this->search)
            ->when($this->filterStatus, fn ($q) => $q->where('service_status', $this->filterStatus))
            ->when($this->filterZone, fn ($q) => $q->where('zone_id', $this->filterZone))
            ->when($this->filterPlan, fn ($q) => $q->where('plan_id', $this->filterPlan))
            ->when($this->filterRepeater, fn ($q) => $q->where('repeater_id', $this->filterRepeater))
            ->when($this->filterRisk, fn ($q) => $q->where('risk_level', $this->filterRisk));
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->orderBy('code')->get();

        return app(ReportExporter::class)->download("clientes", $format, [
            'Código' => 'code', 'Nombre' => 'full_name', 'Teléfono' => 'phone', 'WhatsApp' => 'whatsapp', 'Correo' => 'email',
            'Dirección' => 'address', 'Zona' => 'zone.name', 'Plan' => 'plan.name', 'Repetidor' => 'repeater.code',
            'IP' => 'ip_address', 'MAC' => 'mac_address', 'PPPoE' => 'pppoe_user',
            'Estado' => fn ($c) => \App\Support\Catalog::label('client_status', $c->service_status),
            'Saldo' => fn ($c) => (float) $c->balance, 'Riesgo' => 'risk_level', 'Instalación' => fn ($c) => $c->installation_date?->format('d/m/Y'),
        ], $rows, 'Clientes');
    }

    public function render()
    {
        $counts = Client::query()->selectRaw('service_status, COUNT(*) n')->groupBy('service_status')->pluck('n', 'service_status');

        return view('livewire.clients.index', [
            'clients' => $this->applySort($this->query(), 'code', 'desc')->paginate($this->perPage),
            'counts' => $counts,
            'zones' => Zone::orderBy('name')->pluck('name', 'id'),
            'plans' => Plan::orderBy('price')->pluck('name', 'id'),
            'repeaters' => Repeater::orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]),
        ]);
    }
}
