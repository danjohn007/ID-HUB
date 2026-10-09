<?php

namespace App\Livewire\Network\Towers;

use App\Livewire\Concerns\Toasts;
use App\Models\Prospect;
use App\Models\Repeater;
use App\Models\Sector;
use App\Models\Tower;
use App\Models\Zone;
use App\Services\CoverageService;
use App\Support\MapData;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Torres, sectores y mapa de cobertura (sección 18). Identifica zonas con alta demanda
 * y baja cobertura para evaluar nuevos repetidores.
 */
#[Title('Torres y cobertura')]
class Index extends Component
{
    use Toasts;

    #[Url(as: 'torre', except: '')]
    public string $selectedTower = '';

    public bool $showTowerForm = false;

    public ?int $towerId = null;

    public array $tform = [];

    public bool $showSectorForm = false;

    public ?int $sectorId = null;

    public array $sform = [];

    public int $pickerVersion = 0;

    protected ?array $demandCache = null;

    public function newTower(): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $this->towerId = null;
        $next = (int) Tower::query()->selectRaw('MAX(CAST(SUBSTRING(code, 3) AS UNSIGNED)) m')->value('m') + 1;
        $this->tform = ['code' => 'T-'.str_pad((string) $next, 2, '0', STR_PAD_LEFT), 'name' => '', 'zone_id' => null, 'address' => '', 'lat' => null, 'lng' => null,
            'height_m' => null, 'owner' => '', 'monthly_cost' => 0, 'status' => 'online', 'notes' => ''];
        $this->pickerVersion++;
        $this->showTowerForm = true;
    }

    public function editTower(int $id): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $t = Tower::findOrFail($id);
        $this->towerId = $t->id;
        $this->tform = $t->only(['code', 'name', 'zone_id', 'address', 'lat', 'lng', 'height_m', 'owner', 'monthly_cost', 'status', 'notes']);
        foreach (['lat', 'lng', 'height_m', 'monthly_cost'] as $k) {
            $this->tform[$k] = $this->tform[$k] !== null ? (string) (float) $this->tform[$k] : null;
        }
        $this->pickerVersion++;
        $this->showTowerForm = true;
    }

    public function saveTower(): void
    {
        $this->authorize('network.manage');
        $tid = tenant()->id;
        $data = $this->validate([
            'tform.code' => ['required', 'string', 'max:30', Rule::unique('towers', 'code')->where('tenant_id', $tid)->ignore($this->towerId)],
            'tform.name' => 'required|string|max:150',
            'tform.zone_id' => ['nullable', Rule::exists('zones', 'id')->where('tenant_id', $tid)],
            'tform.address' => 'nullable|string|max:190',
            'tform.lat' => 'required|numeric|between:-90,90',
            'tform.lng' => 'required|numeric|between:-180,180',
            'tform.height_m' => 'nullable|numeric|min:0|max:500',
            'tform.owner' => 'nullable|string|max:150',
            'tform.monthly_cost' => 'nullable|numeric|min:0',
            'tform.status' => ['required', Rule::in(array_keys(config('wisp.device_status')))],
            'tform.notes' => 'nullable|string|max:2000',
        ], [], ['tform.code' => 'clave', 'tform.name' => 'nombre', 'tform.lat' => 'latitud', 'tform.lng' => 'longitud', 'tform.height_m' => 'altura'])['tform'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['monthly_cost'] ??= 0;
        $tower = $this->towerId ? tap(Tower::findOrFail($this->towerId))->update($data) : Tower::create($data);
        $this->showTowerForm = false;
        $this->toast("Torre {$tower->code} guardada");
        $this->refreshMap();
    }

    public function deleteTower(int $id): void
    {
        $this->authorize('network.manage');
        $t = Tower::withCount('repeaters')->findOrFail($id);
        if ($t->repeaters_count) {
            $this->toast("La torre {$t->code} tiene {$t->repeaters_count} repetidores asociados; reasígnalos antes de eliminarla.", 'error');

            return;
        }
        $t->delete();
        $this->toast("Torre {$t->code} eliminada", 'warning');
        $this->refreshMap();
    }

    public function newSector(?int $towerId = null): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $this->sectorId = null;
        $this->sform = ['tower_id' => $towerId ?: ($this->selectedTower ?: null), 'repeater_id' => null, 'name' => '', 'azimuth' => 0, 'beamwidth' => 90,
            'range_km' => 3, 'height_m' => null, 'frequency' => '5.8 GHz', 'power_dbm' => null, 'technology' => 'airMAX AC'];
        $this->showSectorForm = true;
    }

    public function editSector(int $id): void
    {
        $this->authorize('network.manage');
        $this->resetValidation();
        $s = Sector::findOrFail($id);
        $this->sectorId = $s->id;
        $this->sform = $s->only(['tower_id', 'repeater_id', 'name', 'azimuth', 'beamwidth', 'range_km', 'height_m', 'frequency', 'power_dbm', 'technology']);
        foreach (['range_km', 'height_m', 'power_dbm'] as $k) {
            $this->sform[$k] = $this->sform[$k] !== null ? (string) (float) $this->sform[$k] : null;
        }
        $this->showSectorForm = true;
    }

    public function saveSector(): void
    {
        $this->authorize('network.manage');
        $tid = tenant()->id;
        $data = $this->validate([
            'sform.tower_id' => ['required', Rule::exists('towers', 'id')->where('tenant_id', $tid)],
            'sform.repeater_id' => ['nullable', Rule::exists('repeaters', 'id')->where('tenant_id', $tid)],
            'sform.name' => 'required|string|max:150',
            'sform.azimuth' => 'required|integer|between:0,359',
            'sform.beamwidth' => 'required|integer|between:1,360',
            'sform.range_km' => 'required|numeric|min:0.1|max:100',
            'sform.height_m' => 'nullable|numeric|min:0|max:500',
            'sform.frequency' => 'nullable|string|max:60',
            'sform.power_dbm' => 'nullable|numeric|between:-30,60',
            'sform.technology' => 'nullable|string|max:80',
        ], [], ['sform.tower_id' => 'torre', 'sform.name' => 'nombre', 'sform.azimuth' => 'azimut', 'sform.beamwidth' => 'apertura', 'sform.range_km' => 'alcance', 'sform.power_dbm' => 'potencia'])['sform'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $sector = $this->sectorId ? tap(Sector::findOrFail($this->sectorId))->update($data) : Sector::create($data);
        $this->showSectorForm = false;
        $this->toast("Sector {$sector->name} guardado");
        $this->refreshMap();
    }

    public function deleteSector(int $id): void
    {
        $this->authorize('network.manage');
        Sector::findOrFail($id)->delete();
        $this->toast('Sector eliminado', 'warning');
        $this->refreshMap();
    }

    public function focusTower(int $id): void
    {
        $this->selectedTower = (string) $id;
        if ($t = Tower::find($id)) {
            $this->dispatch('map-focus-coverage-map', lat: (float) $t->lat, lng: (float) $t->lng, zoom: 14);
        }
    }

    protected function refreshMap(): void
    {
        $this->dispatch('map-data-coverage-map', data: $this->mapData());
    }

    protected function mapData(): array
    {
        return [
            'markers' => array_merge(MapData::towers(), MapData::repeaters(), MapData::clients(), MapData::prospects()),
            'sectors' => MapData::sectors(),
            'circles' => array_merge(MapData::coverageCircles(), collect($this->demand()['hotspots'])->map(fn ($h) => [
                'lat' => $h['lat'], 'lng' => $h['lng'], 'radius' => 600, 'color' => '#E5484D', 'opacity' => 0.15, 'dash' => true, 'layer' => 'demand',
                'popup' => '<b>Alta demanda sin cobertura</b><br>'.$h['count'].' prospectos · '.$h['nearest'],
            ])->all()),
        ];
    }

    /** Prospectos activos por zona con/sin cobertura y concentraciones de demanda sin cobertura. */
    protected function demand(): array
    {
        if ($this->demandCache !== null) {
            return $this->demandCache;
        }
        $coverage = app(CoverageService::class);
        $prospects = Prospect::query()->whereNotIn('status', ['convertido', 'descartado'])->with('zone:id,name')->get(['id', 'zone_id', 'lat', 'lng', 'has_coverage', 'status', 'created_at']);
        $rows = [];
        $uncovered = [];
        foreach ($prospects as $p) {
            $has = $p->has_coverage;
            if ($has === null && $p->lat) {
                $has = (bool) $coverage->check((float) $p->lat, (float) $p->lng)['has_coverage'];
            }
            $zone = $p->zone?->name ?? 'Sin zona';
            $rows[$zone] ??= ['zone' => $zone, 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'recent' => 0];
            $rows[$zone]['total']++;
            $has ? $rows[$zone]['covered']++ : $rows[$zone]['uncovered']++;
            if ($p->created_at?->gte(now()->subDays(30))) {
                $rows[$zone]['recent']++;
            }
            if (! $has && $p->lat) {
                $uncovered[] = $p;
            }
        }
        $rows = collect($rows)->map(function ($r) {
            $r['ratio'] = $r['total'] ? round($r['uncovered'] / $r['total'] * 100) : 0;
            $r['flag'] = $r['uncovered'] >= 5 && $r['ratio'] >= 15;

            return $r;
        })->sortByDesc('uncovered')->values()->all();

        // Concentraciones (~1 km) de prospectos sin cobertura
        $repeaters = Repeater::query()->whereNotNull('lat')->get(['id', 'code', 'lat', 'lng']);
        $hotspots = collect($uncovered)->groupBy(fn ($p) => round((float) $p->lat, 2).','.round((float) $p->lng, 2))
            ->map(function ($g) use ($repeaters) {
                $lat = $g->avg(fn ($p) => (float) $p->lat);
                $lng = $g->avg(fn ($p) => (float) $p->lng);
                $near = $repeaters->map(fn ($r) => ['code' => $r->code, 'd' => haversine_km($lat, $lng, (float) $r->lat, (float) $r->lng)])->sortBy('d')->first();

                return ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'count' => $g->count(),
                    'nearest' => $near ? 'repetidor más cercano '.$near['code'].' a '.round($near['d'], 1).' km' : 'sin repetidores'];
            })->filter(fn ($h) => $h['count'] >= 2)->sortByDesc('count')->take(6)->values()->all();

        return $this->demandCache = ['zones' => $rows, 'hotspots' => $hotspots, 'total' => $prospects->count()];
    }

    public function render()
    {
        $towers = Tower::query()->with(['zone:id,name', 'sectors.repeater:id,code'])->withCount('repeaters')->orderBy('code')->get();

        return view('livewire.network.towers.index', [
            'towers' => $towers,
            'demand' => $this->demand(),
            'map' => $this->mapData(),
            'zones' => Zone::query()->orderBy('name')->pluck('name', 'id'),
            'towerOptions' => $towers->mapWithKeys(fn ($t) => [$t->id => $t->code.' · '.$t->name]),
            'repeaterOptions' => Repeater::query()->orderBy('code')->get()->mapWithKeys(fn ($r) => [$r->id => $r->code.' · '.$r->name]),
            'sectorCount' => $towers->sum(fn ($t) => $t->sectors->count()),
        ]);
    }
}
