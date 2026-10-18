<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Resources\InventoryMovementResource;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Support\InputRules;
use Illuminate\Http\Request;

/**
 * Materiales usados en campo (sección 23): descuentan inventario con InventoryService,
 * igual que el detalle del ticket / la app de campo web (ManagesTicketWork).
 *
 * $link: ['ticket_id' => .., 'client_id' => ..] o ['installation_order_id' => .., 'client_id' => ..]
 */
trait RegistersMaterials
{
    protected function registerMaterial(Request $request, array $link, string $folio, string $where): InventoryMovementResource
    {
        $data = $this->check($request, [
            'item_id' => ['required', 'integer', $this->existsInTenant('inventory_items')],
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'type' => 'nullable|in:instalacion,salida',
            'serial' => 'nullable|string|max:120|'.InputRules::TEXT,
        ], ['item_id' => 'artículo', 'quantity' => 'cantidad', 'serial' => 'serie']);

        $inventory = app(InventoryService::class);
        $item = InventoryItem::query()->where('active', true)->findOrFail($data['item_id']);
        $movement = $inventory->move($item, $data['type'] ?? 'instalacion', (float) $data['quantity'], $link + [
            'serial' => ($data['serial'] ?? '') ?: null,
            'reference' => $folio,
            'notes' => "Material usado en {$where} {$folio}",
        ]);

        return new InventoryMovementResource($movement->load('item', 'user'));
    }

    /** Devuelve al almacén un material registrado por error (movimiento "devolucion"). */
    protected function returnMaterialMovement(InventoryMovement $m, array $link, string $folio, string $notes): InventoryMovementResource
    {
        $movement = app(InventoryService::class)->move($m->item, 'devolucion', abs((float) $m->quantity), $link + [
            'serial' => $m->serial,
            'reference' => $folio,
            'notes' => $notes,
        ]);

        return new InventoryMovementResource($movement->load('item', 'user'));
    }
}
