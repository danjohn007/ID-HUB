<div>
    <x-ui.page-header title="Torres y cobertura" subtitle="Torres, sectores (azimut, apertura, alcance) y análisis de demanda sin cobertura">
        @can('network.manage')
            <x-ui.button variant="secondary" icon="plus" wire:click="newSector">Nuevo sector</x-ui.button>
            <x-ui.button icon="plus" wire:click="newTower">Nueva torre</x-ui.button>
        @endcan
    </x-ui.page-header>

    @php $flagged = collect($demand['zones'])->where('flag', true); $uncovered = collect($demand['zones'])->sum('uncovered'); @endphp
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat variant="soft" label="Torres" :value="$towers->count()" icon="building-office-2" color="warning" :sub="$towers->sum('repeaters_count').' repetidores'" />
        <x-ui.stat variant="soft" label="Sectores" :value="$sectorCount" icon="signal" color="brand" />
        <x-ui.stat variant="soft" label="Prospectos sin cobertura" :value="$uncovered" icon="map-pin" color="danger" :sub="'de '.$demand['total'].' prospectos activos'" />
        <x-ui.stat variant="soft" label="Zonas con alta demanda" :value="$flagged->count()" icon="fire" color="purple" sub="y baja cobertura" />
    </div>

    <div class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
        <x-ui.card :padding="false">
            <x-slot:title>Mapa de cobertura</x-slot:title>
            <x-slot:actions>
                <div class="hidden items-center gap-3 text-xs text-slate-500 sm:flex">
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-warning-600"></span>Torres</span>
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-brand-600/40"></span>Sectores</span>
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-purple-600"></span>Prospectos</span>
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span>Sin cobertura</span>
                    <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full border border-dashed border-danger-600 bg-danger-600/20"></span>Demanda</span>
                </div>
            </x-slot:actions>
            <x-ui.map id="coverage-map" :markers="$map['markers']" :sectors="$map['sectors']" :circles="$map['circles']" height="520px" fit cluster class="rounded-t-none border-0" />
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card title="Alta demanda + baja cobertura" subtitle="Prospectos activos por zona" :padding="false">
                <x-ui.table>
                    <x-slot:head><th>Zona</th><th class="text-right">Prospectos</th><th class="text-right">Sin cobertura</th><th class="text-right">30 días</th></x-slot:head>
                    @forelse ($demand['zones'] as $z)
                        <tr @class(['bg-danger-50/60' => $z['flag']])>
                            <td class="text-sm font-medium text-slate-800">{{ $z['zone'] }}@if ($z['flag'])<div><x-ui.badge color="danger" size="xs">Evaluar nuevo repetidor</x-ui.badge></div>@endif</td>
                            <td class="text-right font-semibold">{{ $z['total'] }}</td>
                            <td class="text-right"><span class="font-semibold {{ $z['uncovered'] ? 'text-danger-600' : 'text-slate-500' }}">{{ $z['uncovered'] }}</span> <span class="text-xs text-slate-400">{{ $z['ratio'] }}%</span></td>
                            <td class="text-right text-xs text-slate-600">{{ $z['recent'] }}</td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="4" icon="map-pin" title="Sin prospectos activos" />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
            <x-ui.card title="Concentraciones sin cobertura">
                @forelse ($demand['hotspots'] as $h)
                    <div class="flex items-center justify-between gap-2 border-b border-slate-100 py-2 last:border-0">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-slate-800">{{ $h['count'] }} prospectos en ~1 km</div>
                            <div class="truncate text-xs text-slate-500">{{ $h['lat'] }}, {{ $h['lng'] }} · {{ $h['nearest'] }}</div>
                        </div>
                        <x-ui.button size="sm" variant="ghost" icon="map-pin" x-on:click="window.dispatchEvent(new CustomEvent('map-focus-coverage-map', { detail: { lat: {{ $h['lat'] }}, lng: {{ $h['lng'] }}, zoom: 15 } }))" title="Ver en mapa" />
                    </div>
                @empty
                    <p class="text-sm text-slate-500">No hay concentraciones relevantes de prospectos sin cobertura.</p>
                @endforelse
                <p class="mt-3 text-2xs text-slate-400">Cruce de prospectos, radio de los repetidores y sectores. Sirve como apoyo para evaluar la instalación de un nuevo repetidor.</p>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card title="Torres y sectores" :padding="false">
        <x-ui.table>
            <x-slot:head><th>Torre</th><th>Zona</th><th>Altura</th><th>Sectores</th><th>Repetidores</th><th>Estado</th><th></th></x-slot:head>
            @forelse ($towers as $t)
                <tr wire:key="t{{ $t->id }}" @class(['bg-brand-50/50' => (string) $t->id === $selectedTower])>
                    <td>
                        <button type="button" wire:click="focusTower({{ $t->id }})" class="text-left">
                            <span class="block font-semibold text-slate-800 hover:text-brand-600">{{ $t->code }} · {{ $t->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $t->address ?: ((float) $t->lat.', '.(float) $t->lng) }}{{ $t->owner ? ' · '.$t->owner : '' }}</span>
                        </button>
                    </td>
                    <td class="text-xs">{{ $t->zone?->name ?? '—' }}</td>
                    <td class="text-xs">{{ $t->height_m ? (float) $t->height_m.' m' : '—' }}</td>
                    <td class="min-w-[300px]">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($t->sectors as $s)
                                <span class="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-1 text-2xs text-slate-700 ring-1 ring-slate-200" title="{{ $s->frequency }} · {{ $s->technology }}{{ $s->power_dbm ? ' · '.(float) $s->power_dbm.' dBm' : '' }}{{ $s->height_m ? ' · '.(float) $s->height_m.' m' : '' }}">
                                    <b>{{ $s->name }}</b> {{ $s->azimuth }}°/{{ $s->beamwidth }}° · {{ (float) $s->range_km }} km{{ $s->repeater ? ' · '.$s->repeater->code : '' }}
                                    @can('network.manage')
                                        <button type="button" wire:click="editSector({{ $s->id }})" class="text-slate-400 hover:text-brand-600" title="Editar"><x-heroicon-m-pencil class="h-3 w-3" /></button>
                                        <button type="button" wire:click="deleteSector({{ $s->id }})" wire:confirm="¿Eliminar el sector {{ $s->name }}?" class="text-slate-400 hover:text-danger-600" title="Eliminar"><x-heroicon-m-x-mark class="h-3 w-3" /></button>
                                    @endcan
                                </span>
                            @endforeach
                            @can('network.manage')
                                <button type="button" wire:click="newSector({{ $t->id }})" class="rounded-lg px-2 py-1 text-2xs font-semibold text-brand-600 hover:bg-brand-50">+ Sector</button>
                            @endcan
                        </div>
                    </td>
                    <td class="text-sm font-semibold">{{ $t->repeaters_count }}</td>
                    <td><x-ui.status catalog="device_status" :value="$t->status" size="xs" /></td>
                    <td class="text-right whitespace-nowrap">
                        @can('network.manage')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="editTower({{ $t->id }})" title="Editar" />
                            <x-ui.button variant="ghost" size="sm" icon="trash" wire:click="deleteTower({{ $t->id }})" wire:confirm="¿Eliminar la torre {{ $t->code }}?" title="Eliminar" />
                        @endcan
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="building-office-2" title="Sin torres registradas" />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- Modal torre --}}
    <x-ui.modal wire:model="showTowerForm" :title="$towerId ? 'Editar torre' : 'Nueva torre'" size="3xl">
        <form wire:submit="saveTower" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-ui.input label="Clave" wire:model="tform.code" filter="upper" maxlength="30" required />
                <x-ui.input label="Nombre" wire:model="tform.name" filter="text" maxlength="150" required class="md:col-span-2" />
                <x-ui.select label="Estado" wire:model="tform.status" :options="config('wisp.device_status')" />
                <x-ui.select label="Zona" wire:model="tform.zone_id" :options="$zones" placeholder="—" />
                <x-ui.input label="Dirección" wire:model="tform.address" filter="text" maxlength="190" class="md:col-span-3" />
                <x-ui.input label="Latitud" wire:model.blur="tform.lat" filter="signed" required />
                <x-ui.input label="Longitud" wire:model.blur="tform.lng" filter="signed" required />
                <x-ui.input label="Altura" type="number" step="0.1" min="0" max="500" wire:model="tform.height_m" suffix="m" />
                <x-ui.input label="Costo mensual" type="number" step="0.01" min="0" max="10000000" wire:model="tform.monthly_cost" prefix="$" />
                <x-ui.input label="Propietario / renta" wire:model="tform.owner" filter="text" maxlength="150" class="md:col-span-4" />
            </div>
            <div wire:key="tpick-{{ $pickerVersion }}">
                <x-ui.map :id="'tpick'.$pickerVersion" picker picker-lat="tform.lat" picker-lng="tform.lng"
                          :value="! empty($tform['lat']) && ! empty($tform['lng']) ? ['lat' => (float) $tform['lat'], 'lng' => (float) $tform['lng']] : null"
                          :center="! empty($tform['lat']) && ! empty($tform['lng']) ? [(float) $tform['lat'], (float) $tform['lng']] : null"
                          :zoom="! empty($tform['lat']) ? 15 : 12" height="240px" :layers-control="false" />
            </div>
            <x-ui.textarea label="Notas" wire:model="tform.notes" rows="2" maxlength="2000" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveTower">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Modal sector --}}
    <x-ui.modal wire:model="showSectorForm" :title="$sectorId ? 'Editar sector' : 'Nuevo sector'" size="2xl">
        <form wire:submit="saveSector" class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-ui.select label="Torre" wire:model="sform.tower_id" :options="$towerOptions" placeholder="Selecciona..." required />
            <x-ui.input label="Nombre" wire:model="sform.name" filter="text" maxlength="150" required placeholder="Sector Norte 120°" />
            <x-ui.select label="Repetidor que atiende" wire:model="sform.repeater_id" :options="$repeaterOptions" placeholder="—" />
            <x-ui.input label="Azimut" type="number" min="0" max="359" wire:model="sform.azimuth" suffix="°" required />
            <x-ui.input label="Apertura" type="number" min="1" max="360" wire:model="sform.beamwidth" suffix="°" required />
            <x-ui.input label="Alcance" type="number" step="0.1" min="0.1" max="100" wire:model="sform.range_km" suffix="km" required />
            <x-ui.input label="Altura" type="number" step="0.1" min="0" max="500" wire:model="sform.height_m" suffix="m" />
            <x-ui.input label="Frecuencia" wire:model="sform.frequency" filter="text" maxlength="60" placeholder="5.8 GHz / canal 5745" />
            <x-ui.input label="Potencia" type="number" step="0.5" min="-30" max="60" wire:model="sform.power_dbm" suffix="dBm" />
            <x-ui.input label="Tecnología" wire:model="sform.technology" filter="text" maxlength="80" placeholder="airMAX AC, LTU, ePMP..." class="md:col-span-3" />
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button icon="check" wire:click="saveSector">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
