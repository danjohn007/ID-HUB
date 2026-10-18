<?php

namespace App\Http\Resources;

/** Material usado en un ticket u orden de instalación (movimiento de inventario). */
class InventoryMovementResource extends ApiResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => self::catalog('inventory_movement_types', $this->type),
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id, 'sku' => $this->item->sku, 'name' => $this->item->name, 'unit' => $this->item->unit,
            ] : null),
            'quantity' => round(abs((float) $this->quantity), 2),
            'serial' => $this->serial,
            'returnable' => in_array($this->type, ['instalacion', 'salida'], true),
            'user' => $this->whenLoaded('user', fn () => $this->user ? new UserSummaryResource($this->user) : null),
            'created_at' => self::datetime($this->created_at),
        ];
    }
}
