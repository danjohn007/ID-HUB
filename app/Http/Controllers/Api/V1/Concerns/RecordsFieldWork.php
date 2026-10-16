<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\InventoryService;
use App\Support\InputRules;
use Illuminate\Http\Request;

/**
 * Trabajo de campo compartido por tickets e instalaciones en la API (app del técnico):
 * firma del cliente (PNG por archivo o data URL) y materiales descontados del inventario.
 */
trait RecordsFieldWork
{
    /**
     * Valida y devuelve la firma: archivo PNG `signature` (multipart) o data URL en `signature_data`.
     *
     * @return array{png: string, signed_by: string}
     */
    protected function signatureFrom(Request $request): array
    {
        $data = $this->check($request, [
            'signed_by' => 'required|string|min:3|max:120|'.InputRules::NAME,
            'signature' => 'required_without:signature_data|nullable|file|max:2048|mimes:png',
            'signature_data' => 'required_without:signature|nullable|string|max:3000000',
        ], ['signed_by' => 'nombre de quien firma', 'signature' => 'firma', 'signature_data' => 'firma']);

        if ($request->hasFile('signature')) {
            $png = (string) file_get_contents($request->file('signature')->getRealPath());
        } else {
            $png = preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', (string) $data['signature_data'], $m)
                ? (string) base64_decode($m[1], true) : '';
        }
        if (strlen($png) < 100 || strlen($png) > 2 * 1024 * 1024 || ! str_starts_with($png, "\x89PNG")) {
            $this->invalid('signature', 'La firma no es válida. Envía una imagen PNG.');
        }

        return ['png' => $png, 'signed_by' => trim($data['signed_by'])];
    }

    /** Descuenta del inventario un material usado en el trabajo (instalación o salida). */
    protected function recordMaterial(Request $request, array $attrs): InventoryMovement
    {
        $data = $this->check($request, [
            'item_id' => ['required', 'integer', $this->existsInTenant('inventory_items')],
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'type' => 'nullable|in:instalacion,salida',
            'serial' => 'nullable|string|max:120|'.InputRules::TEXT,
        ], ['item_id' => 'artículo', 'quantity' => 'cantidad', 'type' => 'tipo', 'serial' => 'serie']);

        $item = InventoryItem::query()->where('active', true)->find($data['item_id']);
        if (! $item) {
            $this->invalid('item_id', 'El artículo no está activo.');
        }

        return app(InventoryService::class)->move($item, $data['type'] ?? 'instalacion', (float) $data['quantity'], $attrs + [
            'serial' => $data['serial'] ?? null,
        ])->load('item', 'user');
    }

    /** Devuelve al almacén un material registrado por error. */
    protected function returnMovement(InventoryMovement $m, array $attrs): void
    {
        app(InventoryService::class)->move($m->item, 'devolucion', abs((float) $m->quantity), $attrs + ['serial' => $m->serial]);
    }

    protected function invalid(string $field, string $message): never
    {
        abort(response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422));
    }
}
