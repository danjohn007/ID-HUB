<?php

namespace App\Livewire\Billing\Concerns;

use App\Models\Client;
use App\Models\User;
use App\Services\Billing\CashCutService;
use App\Services\BillingService;
use Livewire\Attributes\Computed;

/**
 * Modal "Registrar pago" con búsqueda de cliente (debounce) para las pantallas de Cobranza.
 * Vista: @include('livewire.billing.partials.payment-modal')
 */
trait RegistersPayments
{
    public bool $showPayment = false;

    public string $payClientSearch = '';

    public ?int $payClientId = null;

    public array $pay = ['amount' => '', 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'notify' => true, 'collector_id' => ''];

    public function openPayment(?int $clientId = null): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->payClientSearch = '';
        $this->payClientId = null;
        $this->pay = ['amount' => '', 'method' => 'efectivo', 'reference' => '', 'notes' => '', 'notify' => true, 'collector_id' => ''];
        if ($clientId) {
            $this->selectPayClient($clientId);
        }
        $this->showPayment = true;
    }

    public function selectPayClient(int $id): void
    {
        $client = Client::findOrFail($id);
        $this->payClientId = $client->id;
        $this->pay['amount'] = $client->balance > 0 ? (string) round((float) $client->balance, 2) : (string) ($client->plan?->price ?? '');
        $this->pay['collector_id'] = (string) ($client->collector_id ?? '');
        $this->payClientSearch = '';
    }

    public function clearPayClient(): void
    {
        $this->payClientId = null;
        $this->pay['amount'] = '';
    }

    #[Computed]
    public function payClientResults()
    {
        if (mb_strlen(trim($this->payClientSearch)) < 2) {
            return collect();
        }

        return Client::query()->search($this->payClientSearch)->whereNot('service_status', 'prospecto')
            ->orderByDesc('balance')->limit(8)
            ->get(['id', 'code', 'first_name', 'last_name', 'balance', 'service_status', 'address', 'collector_id']);
    }

    #[Computed]
    public function payClient(): ?Client
    {
        return $this->payClientId ? Client::with('plan:id,name,price')->find($this->payClientId) : null;
    }

    #[Computed]
    public function collectorOptions()
    {
        return User::forTenant()->active()->role('cobrador')->orderBy('name')->pluck('name', 'id');
    }

    public function savePayment(BillingService $billing, CashCutService $cuts): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'payClientId' => 'required|integer',
            'pay.amount' => 'required|numeric|min:1|max:1000000',
            'pay.method' => 'required|in:'.implode(',', array_keys(config('wisp.payment_methods'))),
            'pay.reference' => 'nullable|string|max:120',
            'pay.notes' => 'nullable|string|max:190',
            'pay.collector_id' => 'nullable|integer',
        ], [], ['payClientId' => 'cliente', 'pay.amount' => 'importe', 'pay.method' => 'método', 'pay.reference' => 'referencia']);

        $client = Client::findOrFail($this->payClientId);
        $collectorId = $this->pay['method'] === 'efectivo' && $this->pay['collector_id']
            ? User::forTenant()->role('cobrador')->whereKey($this->pay['collector_id'])->value('id')
            : null;

        $payment = $billing->registerPayment($client, (float) $this->pay['amount'], $this->pay['method'], [
            'reference' => $this->pay['reference'] ?: null,
            'notes' => $this->pay['notes'] ?: null,
            'notify' => (bool) $this->pay['notify'],
            'collector_id' => $collectorId,
        ]);
        if ($collectorId) {
            $cuts->attach($payment);
        }

        $this->showPayment = false;
        $this->payClientId = null;
        unset($this->payClient);
        $this->dispatch('payment-registered', id: $payment->id);
        $this->toast("Pago {$payment->folio} registrado por ".money($payment->amount).' · '.$client->full_name);
    }
}
