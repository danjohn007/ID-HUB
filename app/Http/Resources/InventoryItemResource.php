<?php

namespace App\Http\Resources;

/** Artículo de inventario con su existencia (sección 38). */
class InventoryItemResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'category' => ['value' => $this->category, 'label' => config("wisp.inventory_categories.{$this->category}", $this->category)],
            'brand' => $this->brand,
            'model' => $this->model,
            'unit' => $this->unit,
            'stock' => (float) $this->stock,
            'min_stock' => (float) $this->min_stock,
            'stock_state' => $this->stockState(),
            'serialized' => (bool) $this->serialized,
            'location' => $this->location,
        ];
    }
}
