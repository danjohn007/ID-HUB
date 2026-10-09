<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\DocumentController;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\TenantContext;

/**
 * Documentos PDF accesibles por enlace firmado (sin sesión), para las apps:
 * reutiliza DocumentController dentro del contexto del WISP dueño del documento.
 */
class SignedDocumentController extends ApiController
{
    public function receipt(int $payment)
    {
        $model = Payment::withoutGlobalScopes()->where('status', 'completed')->findOrFail($payment);
        $this->enterTenant($model->tenant_id);

        return app(DocumentController::class)->receipt(Payment::query()->findOrFail($model->id));
    }

    public function statement(int $client)
    {
        $model = Client::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($client);
        $this->enterTenant($model->tenant_id);

        return app(DocumentController::class)->statement(Client::query()->findOrFail($model->id));
    }

    protected function enterTenant(int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);
        abort_unless($tenant && $tenant->isActive(), 404);
        app(TenantContext::class)->set($tenant);
    }
}
