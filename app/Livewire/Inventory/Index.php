<?php

namespace App\Livewire\Inventory;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Client;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\NetworkDevice;
use App\Models\Ticket;
use App\Services\InventoryService;
use App\Services\ReportExporter;
use App\Support\Catalog;
use App\Support\InputRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inventario (sección 38): artículos, existencias, mínimos/máximos, movimientos,
 * valuación y equipos instalados (sección 39).
 */
#[Title('Inventario')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url]
    public string $tab = 'articulos';

    #[Url(as: 'categoria', except: '')]
    public string $filterCategory = '';

    #[Url(as: 'existencia', except: '')]
    public string $filterStock = '';

    #[Url(as: 'tipo', except: '')]
    public string $filterType = '';

    public string $filterItem = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterDeviceType = '';

    public bool $showInactive = false;

    protected array $sortable = ['sku', 'name', 'category', 'stock', 'unit_cost', 'created_at'];

    // Artículo
    public bool $showItem = false;

    public ?int $itemId = null;

    public array $item = [];

    // Movimiento
    public bool $showMovement = false;

    public array $movement = [];

    public string $clientSearch = '';

    // Historial
    public bool $showHistory = false;

    public ?int $historyId = null;

    public function mount(): void
    {
        $this->resetItem();
        $this->resetMovement();
        $this->filterFrom = now()->startOfMonth()->toDateString();
        $this->filterTo = now()->toDateString();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->search = '';
        $this->sortBy = '';
    }

    protected function resetItem(): void
    {
        $this->itemId = null;
        $this->item = ['sku' => '', 'name' => '', 'category' => 'cpe', 'brand' => '', 'model' => '', 'unit' => 'pza', 'stock' => '0',
            'min_stock' => '0', 'max_stock' => '', 'unit_cost' => '0', 'location' => 'Almacén principal', 'serialized' => false, 'active' => true];
    }

    protected function resetMovement(?int $itemId = null, string $type = 'entrada'): void
    {
        $this->movement = ['item_id' => $itemId ? (string) $itemId : '', 'type' => $type, 'quantity' => '', 'counted' => '', 'unit_cost' => '',
            'reference' => '', 'client_id' => null, 'ticket_folio' => '', 'serial' => '', 'notes' => ''];
        $this->clientSearch = '';
    }

    // ------------------------------------------------------------ Artículos

    public function newItem(): void
    {
        $this->authorize('inventory.manage');
        $this->resetItem();
        $this->resetValidation();
        $this->showItem = true;
    }

    public function editItem(int $id): void
    {
        $this->authorize('inventory.manage');
        $i = InventoryItem::findOrFail($id);
        $this->itemId = $i->id;
        $this->item = [
            'sku' => $i->sku, 'name' => $i->name, 'category' => $i->category, 'brand' => (string) $i->brand, 'model' => (string) $i->model,
            'unit' => $i->unit, 'stock' => (string) (float) $i->stock, 'min_stock' => (string) (float) $i->min_stock,
            'max_stock' => $i->max_stock !== null ? (string) (float) $i->max_stock : '', 'unit_cost' => (string) (float) $i->unit_cost,
            'location' => (string) $i->location, 'serialized' => (bool) $i->serialized, 'active' => (bool) $i->active,
        ];
        $this->resetValidation();
        $this->showItem = true;
    }

    public function saveItem(InventoryService $inventory): void
    {
        $this->authorize('inventory.manage');
        $this->validate([
            'item.sku' => ['required', 'string', 'max:40', InputRules::CODE, Rule::unique('inventory_items', 'sku')->where('tenant_id', tenant()->id)->ignore($this->itemId)],
            'item.name' => 'required|string|max:190|'.InputRules::TEXT,
            'item.category' => 'required|in:'.implode(',', array_keys(config('wisp.inventory_categories'))),
            'item.brand' => 'nullable|string|max:120|'.InputRules::TEXT,
            'item.model' => 'nullable|string|max:120|'.InputRules::TEXT,
            'item.unit' => 'required|string|max:10',
            'item.stock' => $this->itemId ? 'nullable' : 'required|numeric|min:0|max:9999999',
            'item.min_stock' => 'required|numeric|min:0|max:9999999',
            'item.max_stock' => 'nullable|numeric|min:0|max:9999999|gte:item.min_stock',
            'item.unit_cost' => 'required|numeric|min:0|max:9999999',
            'item.location' => 'nullable|string|max:120|'.InputRules::TEXT,
        ], ['item.max_stock.gte' => 'El máximo debe ser mayor o igual al mínimo.'], [
            'item.sku' => 'SKU', 'item.name' => 'nombre', 'item.min_stock' => 'mínimo', 'item.max_stock' => 'máximo', 'item.unit_cost' => 'costo', 'item.stock' => 'existencia inicial',
            'item.brand' => 'marca', 'item.model' => 'modelo', 'item.location' => 'ubicación',
        ]);

        $data = collect($this->item)->except('stock')->all();
        $data['max_stock'] = $data['max_stock'] === '' ? null : $data['max_stock'];
        foreach (['brand', 'model', 'location'] as $k) {
            $data[$k] = $data[$k] ?: null;
        }

        if ($this->itemId) {
            $model = InventoryItem::findOrFail($this->itemId);
            $model->update($data);
            $inventory->checkStock($model->fresh());
            $this->toast('Artículo actualizado');
        } else {
            $model = InventoryItem::create($data + ['stock' => 0]);
            if ((float) $this->item['stock'] > 0) {
                $inventory->move($model, 'entrada', (float) $this->item['stock'], ['unit_cost' => $data['unit_cost'], 'reference' => 'Existencia inicial']);
            } else {
                $inventory->checkStock($model);
            }
            $this->toast("Artículo {$model->sku} creado");
        }
        $this->showItem = false;
    }

    public function toggleActive(int $id, InventoryService $inventory): void
    {
        $this->authorize('inventory.manage');
        $i = InventoryItem::findOrFail($id);
        $i->update(['active' => ! $i->active]);
        $inventory->checkStock($i);
        $this->toast($i->active ? 'Artículo reactivado' : 'Artículo desactivado', $i->active ? 'success' : 'warning');
    }

    public function deleteItem(int $id): void
    {
        $this->authorize('inventory.manage');
        $i = InventoryItem::findOrFail($id);
        if ($i->movements()->exists() || $i->devices()->exists()) {
            $this->toast('El artículo tiene movimientos o equipos; desactívalo en lugar de eliminarlo', 'warning');

            return;
        }
        app(\App\Services\AlertService::class)->resolveFor('low_stock', $i);
        $i->delete();
        $this->showItem = false;
        $this->toast('Artículo eliminado', 'warning');
    }

    // ------------------------------------------------------------ Movimientos

    public function openMovement(?int $itemId = null, string $type = 'entrada'): void
    {
        $this->authorize('inventory.manage');
        $this->resetMovement($itemId, $type);
        $this->resetValidation();
        $this->showHistory = false;
        $this->showMovement = true;
    }

    public function updatedClientSearch(): void
    {
        $this->movement['client_id'] = null;
    }

    public function selectClient(int $id): void
    {
        $c = Client::findOrFail($id);
        $this->movement['client_id'] = $c->id;
        $this->clientSearch = $c->full_name.' · #'.$c->code;
    }

    public function saveMovement(InventoryService $inventory): void
    {
        $this->authorize('inventory.manage');
        $isAdjust = $this->movement['type'] === 'ajuste';
        $this->validate([
            'movement.item_id' => 'required|integer',
            'movement.type' => 'required|in:'.implode(',', array_keys(config('wisp.inventory_movement_types'))),
            'movement.quantity' => $isAdjust ? 'nullable' : 'required|numeric|min:0.01|max:9999999',
            'movement.counted' => $isAdjust ? 'required|numeric|min:0|max:9999999' : 'nullable',
            'movement.unit_cost' => 'nullable|numeric|min:0|max:9999999',
            'movement.reference' => 'nullable|string|max:120|'.InputRules::TEXT,
            'movement.ticket_folio' => 'nullable|string|max:30|'.InputRules::CODE,
            'movement.serial' => 'nullable|string|max:120|'.InputRules::CODE,
            'movement.notes' => 'nullable|string|max:190',
        ], [], ['movement.item_id' => 'artículo', 'movement.quantity' => 'cantidad', 'movement.counted' => 'existencia contada', 'movement.unit_cost' => 'costo unitario',
            'movement.reference' => 'referencia', 'movement.ticket_folio' => 'folio de ticket', 'movement.serial' => 'número de serie', 'movement.notes' => 'notas']);

        $ticketId = null;
        if ($folio = trim($this->movement['ticket_folio'])) {
            $ticket = Ticket::query()->where('folio', $folio)->first();
            if (! $ticket) {
                $this->addError('movement.ticket_folio', 'No existe un ticket con ese folio.');

                return;
            }
            $ticketId = $ticket->id;
            $this->movement['client_id'] ??= $ticket->client_id;
        }

        $item = InventoryItem::findOrFail($this->movement['item_id']);
        $attrs = [
            'unit_cost' => $this->movement['unit_cost'], 'reference' => $this->movement['reference'] ?: null,
            'client_id' => $this->movement['client_id'], 'ticket_id' => $ticketId,
            'serial' => $this->movement['serial'] ?: null, 'notes' => $this->movement['notes'] ?: null,
        ];
        $m = $isAdjust
            ? $inventory->adjustTo($item, (float) $this->movement['counted'], $attrs)
            : $inventory->move($item, $this->movement['type'], (float) $this->movement['quantity'], $attrs);

        $this->showMovement = false;
        if (! $m) {
            $this->toast('La existencia contada coincide; no se generó ajuste', 'info');

            return;
        }
        $item->refresh();
        $this->toast(Catalog::label('inventory_movement_types', $m->type).' registrada · '.$item->name.': '.$inventory->qty($item->stock).' '.$item->unit,
            $item->stock <= $item->min_stock && $item->min_stock > 0 ? 'warning' : 'success');
    }

    public function openHistory(int $id): void
    {
        $this->historyId = $id;
        $this->showHistory = true;
    }

    // ------------------------------------------------------------ Consultas

    protected function itemsQuery(): Builder
    {
        $term = trim($this->search);

        return InventoryItem::query()
            ->when(! $this->showInactive, fn ($q) => $q->where('active', true))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('sku', 'like', "%$term%")->orWhere('name', 'like', "%$term%")
                ->orWhere('brand', 'like', "%$term%")->orWhere('model', 'like', "%$term%")->orWhere('location', 'like', "%$term%")))
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterStock === 'out', fn ($q) => $q->where('stock', '<=', 0))
            ->when($this->filterStock === 'low', fn ($q) => $q->whereColumn('stock', '<=', 'min_stock'))
            ->when($this->filterStock === 'ok', fn ($q) => $q->whereColumn('stock', '>', 'min_stock')->where(fn ($w) => $w->whereNull('max_stock')->orWhereColumn('stock', '<=', 'max_stock')))
            ->when($this->filterStock === 'over', fn ($q) => $q->whereNotNull('max_stock')->whereColumn('stock', '>', 'max_stock'));
    }

    protected function movementsQuery(): Builder
    {
        $term = trim($this->search);

        return InventoryMovement::query()
            ->with(['item:id,sku,name,unit', 'client:id,code,first_name,last_name', 'ticket:id,folio', 'user:id,name', 'installationOrder:id,folio'])
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterItem, fn ($q) => $q->where('inventory_item_id', $this->filterItem))
            ->when($this->filterFrom, fn ($q) => $q->where('created_at', '>=', $this->filterFrom.' 00:00:00'))
            ->when($this->filterTo, fn ($q) => $q->where('created_at', '<=', $this->filterTo.' 23:59:59'))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', "%$term%")->orWhere('serial', 'like', "%$term%")
                ->orWhereHas('item', fn ($i) => $i->where('name', 'like', "%$term%")->orWhere('sku', 'like', "%$term%"))));
    }

    protected function devicesQuery(): Builder
    {
        $term = trim($this->search);

        return NetworkDevice::query()
            ->whereIn('type', ['cpe', 'antenna', 'router'])
            ->with(['client:id,code,first_name,last_name,repeater_id', 'repeater:id,code,name,tower_id', 'repeater.tower:id,code,name', 'tower:id,code,name', 'inventoryItem:id,sku'])
            ->when($this->filterDeviceType, fn ($q) => $q->where('type', $this->filterDeviceType))
            ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('mac', 'like', "%$term%")->orWhere('ip', 'like', "%$term%")
                ->orWhere('serial', 'like', "%$term%")->orWhere('model', 'like', "%$term%")
                ->orWhereHas('client', fn ($c) => $c->search($term))));
    }

    public function export(string $format = 'xlsx')
    {
        $inventory = app(InventoryService::class);
        if ($this->tab === 'movimientos') {
            return app(ReportExporter::class)->download('movimientos-inventario', $format, [
                'Fecha' => 'created_at', 'SKU' => 'item.sku', 'Artículo' => 'item.name',
                'Tipo' => fn ($m) => Catalog::label('inventory_movement_types', $m->type), 'Cantidad' => fn ($m) => (float) $m->quantity,
                'Existencia después' => fn ($m) => (float) $m->stock_after, 'Costo unitario' => fn ($m) => (float) $m->unit_cost,
                'Referencia' => 'reference', 'Serie' => 'serial', 'Cliente' => 'client.full_name', 'Ticket' => 'ticket.folio', 'Usuario' => 'user.name', 'Notas' => 'notes',
            ], $this->movementsQuery()->latest()->limit(10000)->get(), 'Movimientos de inventario');
        }
        if ($this->tab === 'equipos') {
            return app(ReportExporter::class)->download('equipos-instalados', $format, [
                'Equipo' => 'name', 'Tipo' => fn ($d) => config('wisp.device_types.'.$d->type, $d->type), 'Modelo' => 'model', 'MAC' => 'mac', 'IP' => 'ip', 'Serie' => 'serial',
                'Cliente' => 'client.full_name', 'Repetidor' => fn ($d) => ($d->repeater ?? $d->client?->repeater)?->code,
                'Torre' => fn ($d) => ($d->repeater?->tower ?? $d->tower)?->name, 'Estado' => fn ($d) => Catalog::label('device_status', $d->status), 'Instalado' => 'installed_at',
            ], $this->devicesQuery()->orderBy('name')->get(), 'Equipos instalados');
        }
        $rows = $this->itemsQuery()->orderBy('category')->orderBy('name')->get();

        return app(ReportExporter::class)->download('inventario', $format, [
            'SKU' => 'sku', 'Artículo' => 'name', 'Categoría' => fn ($i) => config('wisp.inventory_categories.'.$i->category, $i->category),
            'Marca' => 'brand', 'Modelo' => 'model', 'Ubicación' => 'location', 'Unidad' => 'unit',
            'Existencia' => fn ($i) => (float) $i->stock, 'Mínimo' => fn ($i) => (float) $i->min_stock, 'Máximo' => fn ($i) => $i->max_stock !== null ? (float) $i->max_stock : '',
            'Costo unitario' => fn ($i) => (float) $i->unit_cost, 'Valor' => fn ($i) => round((float) $i->stock * (float) $i->unit_cost, 2),
            'Estado' => fn ($i) => self::stockLabel($i),
        ], $rows, 'Inventario', ['Valuación total' => money($rows->sum(fn ($i) => (float) $i->stock * (float) $i->unit_cost)), 'Artículos' => $rows->count()]);
    }

    public static function stockLabel(InventoryItem $i): string
    {
        return ['danger' => 'Sin existencia', 'warning' => 'Bajo mínimo', 'info' => 'Sobre máximo', 'success' => 'Suficiente'][$i->stockState()] ?? '';
    }

    public function render()
    {
        $inventory = app(InventoryService::class);
        $active = InventoryItem::query()->where('active', true);
        $data = [
            'inv' => $inventory,
            'kpi' => [
                'items' => (clone $active)->count(),
                'value' => (float) (clone $active)->selectRaw('SUM(stock * unit_cost) v')->value('v'),
                'low' => (clone $active)->whereColumn('stock', '<=', 'min_stock')->where('stock', '>', 0)->count(),
                'out' => (clone $active)->where('stock', '<=', 0)->count(),
                'moves' => InventoryMovement::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'installed' => (float) -InventoryMovement::query()->where('type', 'instalacion')->where('created_at', '>=', now()->startOfMonth())->sum('quantity'),
            ],
            'itemOptions' => InventoryItem::query()->orderBy('name')->get(['id', 'sku', 'name', 'stock', 'unit', 'active'])
                ->mapWithKeys(fn ($i) => [$i->id => $i->sku.' · '.$i->name.' ('.$inventory->qty($i->stock).' '.$i->unit.')'.($i->active ? '' : ' [inactivo]')]),
            'clientResults' => $this->showMovement && ! $this->movement['client_id'] && mb_strlen(trim($this->clientSearch)) >= 2
                ? Client::query()->search($this->clientSearch)->limit(6)->get(['id', 'code', 'first_name', 'last_name', 'address'])
                : collect(),
        ];

        if ($this->tab === 'articulos') {
            $data['items'] = $this->applySort($this->itemsQuery(), 'name', 'asc')->paginate($this->perPage);
            $data['byCategory'] = (clone $active)->selectRaw('category, COUNT(*) n, SUM(stock * unit_cost) v')->groupBy('category')->get()->keyBy('category');
        } elseif ($this->tab === 'movimientos') {
            $data['movements'] = $this->movementsQuery()->latest()->latest('id')->paginate($this->perPage);
        } else {
            $data['devices'] = $this->devicesQuery()->orderBy('name')->paginate($this->perPage);
            $data['deviceCounts'] = NetworkDevice::query()->whereIn('type', ['cpe', 'antenna', 'router'])->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type');
        }

        if ($this->showHistory && $this->historyId) {
            $data['historyItem'] = InventoryItem::find($this->historyId);
            $data['history'] = InventoryMovement::query()->where('inventory_item_id', $this->historyId)
                ->with(['client:id,code,first_name,last_name', 'ticket:id,folio', 'user:id,name'])->latest()->latest('id')->limit(100)->get();
        }

        return view('livewire.inventory.index', $data);
    }
}
