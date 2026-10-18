<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryItem;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de inventario para la app del técnico (registrar materiales usados).
 */
class InventoryController extends ApiController
{
    public function items(Request $request): JsonResponse
    {
        $inventory = app(InventoryService::class);
        $items = InventoryItem::query()->where('active', true)
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('sku', 'like', "%$t%")))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->orderBy('name')->limit(300)
            ->get(['id', 'sku', 'name', 'category', 'unit', 'stock', 'min_stock', 'serialized']);

        return $this->ok(['data' => $items->map(fn (InventoryItem $i) => [
            'id' => $i->id,
            'sku' => $i->sku,
            'name' => $i->name,
            'category' => $i->category ? ['value' => $i->category, 'label' => config("wisp.inventory_categories.{$i->category}", $i->category)] : null,
            'unit' => $i->unit,
            'stock' => round((float) $i->stock, 2),
            'stock_label' => $inventory->qty($i->stock).' '.$i->unit,
            'stock_state' => $i->stockState(),
            'serialized' => (bool) $i->serialized,
        ])->values()]);
    }
}
