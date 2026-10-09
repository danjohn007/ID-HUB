<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Services\Payments\PaymentGatewayService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Página pública "Pagar ahora" (sin sesión). El WISP se resuelve a partir del enlace.
 */
class PaymentLinkController extends Controller
{
    public function __construct(protected PaymentGatewayService $gateways) {}

    public function show(string $token)
    {
        $link = $this->link($token);
        $payment = $this->gateways->paymentForLink($link);
        $client = $link->client;
        $openCharges = $client ? $client->charges()->withoutGlobalScopes()->whereIn('status', ['pending', 'partial'])->orderBy('due_date')->get() : collect();

        return view('public.pay.show', [
            'link' => $link,
            'tenant' => $link->tenant,
            'client' => $client,
            'charges' => $openCharges,
            'payment' => $payment,
            'gateways' => $link->isPayable() ? $this->gateways->enabledFor($link->tenant) : collect(),
            'service' => $this->gateways,
        ]);
    }

    public function checkout(Request $request, string $token)
    {
        $link = $this->link($token);
        $data = $request->validate(['provider' => 'required|string|in:'.implode(',', PaymentGatewayService::PROVIDERS)]);
        try {
            $url = $this->gateways->startCheckout($link, $data['provider']);
        } catch (\Throwable $e) {
            Log::warning('No se pudo iniciar checkout', ['link' => $link->id, 'provider' => $data['provider'], 'error' => $e->getMessage()]);

            return back()->with('pay_error', 'No fue posible iniciar el pago con este método. Intenta con otro o más tarde.');
        }

        return redirect()->away($url);
    }

    public function success(Request $request, string $token)
    {
        $link = $this->link($token);
        $provider = (string) $request->query('provider', $link->gateway);
        $result = in_array($provider, PaymentGatewayService::PROVIDERS, true)
            ? $this->gateways->handleReturn($link, $provider, $request)
            : ['status' => 'unknown', 'payment' => $this->gateways->paymentForLink($link)];
        $payment = $result['payment'] ?? $this->gateways->paymentForLink($link);
        $status = $payment ? 'approved' : ($result['status'] === 'unknown' ? 'pending' : $result['status']);

        return view('public.pay.result', [
            'link' => $link->refresh(), 'tenant' => $link->tenant, 'client' => $link->client, 'payment' => $payment,
            'status' => $status, 'provider' => $provider,
        ]);
    }

    public function failure(Request $request, string $token)
    {
        $link = $this->link($token);
        $payment = $this->gateways->paymentForLink($link);

        return view('public.pay.result', [
            'link' => $link, 'tenant' => $link->tenant, 'client' => $link->client, 'payment' => $payment,
            'status' => $payment ? 'approved' : 'rejected', 'provider' => (string) $request->query('provider', $link->gateway),
        ]);
    }

    /** Checkout simulado (sandbox sin credenciales). */
    public function sandbox(string $token, string $provider)
    {
        $link = $this->link($token);
        $gateway = $this->gateways->gatewayFor($link, $provider);
        abort_unless($gateway && $this->gateways->isSimulated($gateway), 404);

        return view('public.pay.sandbox', ['link' => $link, 'tenant' => $link->tenant, 'client' => $link->client, 'gateway' => $gateway, 'provider' => $provider]);
    }

    public function sandboxComplete(Request $request, string $token, string $provider)
    {
        $link = $this->link($token);
        $approve = $request->input('decision') === 'approve';
        if ($approve && ! $link->isPayable() && ! $this->gateways->paymentForLink($link)) {
            return redirect()->route('pay.show', $token);
        }
        try {
            $this->gateways->simulate($link, $provider, $approve);
        } catch (\Throwable $e) {
            abort(404);
        }

        return redirect()->route($approve ? 'pay.success' : 'pay.failure', ['token' => $token, 'provider' => $provider]);
    }

    /** Comprobante PDF del pago hecho con este enlace. */
    public function receipt(string $token, int $payment)
    {
        $link = $this->link($token);
        $p = Payment::withoutGlobalScopes()->where('payment_link_id', $link->id)->whereKey($payment)->firstOrFail();
        $p->load(['client' => fn ($q) => $q->withoutGlobalScopes(), 'allocations.charge' => fn ($q) => $q->withoutGlobalScopes(), 'receivedBy', 'collector']);

        return Pdf::loadView('pdf.receipt', ['payment' => $p, 'title' => 'Comprobante de pago', 'tenant' => $link->tenant])
            ->setPaper([0, 0, 396, 612])->stream("comprobante-{$p->folio}.pdf");
    }

    protected function link(string $token): PaymentLink
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{20,64}$/', $token), 404);
        $link = $this->gateways->resolveLink($token);
        abort_unless($link && $link->tenant->isActive(), 404);

        return $link;
    }
}
