<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventario para la app del técnico (sección 38): existencias por categoría y
 * catálogo de artículos para registrar los materiales usados.
 */
class InventoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $items = InventoryItem::query()->where('active', true)
            ->when($request->query('category'), fn ($q, $v) => $q->whereIn('category', explode(',', $v)))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('sku', 'like', "%$t%")))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock', '>', 0))
            ->orderBy('category')->orderBy('name')->limit(500)->get();

        $categories = $items->groupBy('category')->map(fn ($group, $key) => [
            'value' => $key,
            'label' => config("wisp.inventory_categories.$key", $key),
            'items' => $group->count(),
            'stock' => round((float) $group->sum('stock'), 2),
            'low' => $group->filter(fn ($i) => in_array($i->stockState(), ['danger', 'warning'], true))->count(),
        ])->values();

        return $this->ok([
            'categories' => $categories,
            'data' => InventoryItemResource::collection($items),
        ]);
    }
}
