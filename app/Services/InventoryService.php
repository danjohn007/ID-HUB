<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Movimientos de inventario (sección 38) con existencias consistentes:
 * cada movimiento bloquea el artículo (lockForUpdate), actualiza `stock` y guarda `stock_after`.
 *
 *   app(InventoryService::class)->move($item, 'instalacion', 2, ['ticket_id' => $t->id, 'client_id' => $t->client_id]);
 *
 * Cantidad: siempre positiva para entrada/salida/instalacion/devolucion (el signo lo da el tipo);
 * en 'ajuste' es la diferencia con signo (usar adjustTo() para fijar la existencia contada).
 * Al quedar en o por debajo del mínimo se genera la alerta 'low_stock'; se resuelve al reabastecer.
 */
class InventoryService
{
    public const INBOUND = ['entrada', 'devolucion'];

    public const OUTBOUND = ['salida', 'instalacion'];

    public function move(InventoryItem $item, string $type, float $quantity, array $attrs = []): InventoryMovement
    {
        if (! array_key_exists($type, config('wisp.inventory_movement_types'))) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }
        $delta = match (true) {
            in_array($type, self::INBOUND, true) => abs($quantity),
            in_array($type, self::OUTBOUND, true) => -abs($quantity),
            default => $quantity,
        };
        if ($delta == 0.0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser distinta de cero.']);
        }

        $movement = DB::transaction(function () use ($item, $type, $delta, $attrs) {
            /** @var InventoryItem $locked */
            $locked = InventoryItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $newStock = round((float) $locked->stock + $delta, 2);
            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Existencia insuficiente: hay '.$this->qty($locked->stock).' '.$locked->unit.' de '.$locked->name.'.',
                ]);
            }

            $unitCost = isset($attrs['unit_cost']) && $attrs['unit_cost'] !== '' ? (float) $attrs['unit_cost'] : null;
            // Costo promedio ponderado en entradas
            if ($type === 'entrada' && $unitCost !== null && $unitCost > 0) {
                $base = max(0, (float) $locked->stock);
                $locked->unit_cost = $base + $delta > 0
                    ? round(($base * (float) $locked->unit_cost + $delta * $unitCost) / ($base + $delta), 2)
                    : $unitCost;
            }
            $locked->stock = $newStock;
            $locked->save();

            $movement = InventoryMovement::create([
                'tenant_id' => $locked->tenant_id,
                'inventory_item_id' => $locked->id,
                'type' => $type,
                'quantity' => $delta,
                'unit_cost' => $unitCost ?? $locked->unit_cost,
                'stock_after' => $newStock,
                'serial' => $attrs['serial'] ?? null,
                'reference' => $attrs['reference'] ?? null,
                'client_id' => $attrs['client_id'] ?? null,
                'ticket_id' => $attrs['ticket_id'] ?? null,
                'installation_order_id' => $attrs['installation_order_id'] ?? null,
                'user_id' => $attrs['user_id'] ?? auth()->id(),
                'notes' => isset($attrs['notes']) ? mb_substr((string) $attrs['notes'], 0, 190) : null,
            ]);

            $item->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });

        $this->checkStock($item);

        return $movement;
    }

    /** Ajuste de inventario a una existencia física contada. */
    public function adjustTo(InventoryItem $item, float $counted, array $attrs = []): ?InventoryMovement
    {
        $diff = round($counted - (float) $item->fresh()->stock, 2);

        return $diff == 0.0 ? null : $this->move($item, 'ajuste', $diff, $attrs);
    }

    /** Genera o resuelve la alerta de inventario bajo según la existencia actual. */
    public function checkStock(InventoryItem $item): void
    {
        $alerts = app(AlertService::class);
        if ($item->active && (float) $item->min_stock > 0 && (float) $item->stock <= (float) $item->min_stock) {
            $alerts->raise(
                'low_stock',
                (float) $item->stock <= 0 ? 'critical' : 'warning',
                'Inventario bajo: '.$item->name,
                'Existencia '.$this->qty($item->stock).' '.$item->unit.' (mínimo '.$this->qty($item->min_stock).'). SKU '.$item->sku.($item->location ? ' · '.$item->location : ''),
                $item,
                ['stock' => (float) $item->stock, 'min' => (float) $item->min_stock],
            );
        } else {
            $alerts->resolveFor('low_stock', $item);
        }

        // Sobre-existencia: capital inmovilizado / posible error de captura
        if ($item->active && $item->max_stock !== null && (float) $item->max_stock > 0 && (float) $item->stock > (float) $item->max_stock) {
            $alerts->raise(
                'over_stock',
                'info',
                'Inventario excedido: '.$item->name,
                'Existencia '.$this->qty($item->stock).' '.$item->unit.' (máximo '.$this->qty($item->max_stock).'). SKU '.$item->sku.($item->location ? ' · '.$item->location : '')
                    .'. Revisar compras pendientes o redistribuir el material.',
                $item,
                ['stock' => (float) $item->stock, 'max' => (float) $item->max_stock],
            );
        } else {
            $alerts->resolveFor('over_stock', $item);
        }
    }

    public function qty($value): string
    {
        $v = (float) $value;

        return fmod($v, 1.0) == 0.0 ? number_format($v, 0) : number_format($v, 2);
    }
}
