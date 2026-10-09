<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Payment;
use App\Models\Ticket;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Generación de documentos PDF (sección 41): contrato, orden de instalación,
 * recibo / comprobante de pago, estado de cuenta y evidencia de servicio.
 */
class DocumentController extends Controller
{
    public function contract(Client $client)
    {
        $tenant = tenant();
        $plan = $client->plan;
        $vars = [
            '{empresa}' => $tenant->legal_name ?: $tenant->name,
            '{cliente}' => $client->full_name,
            '{direccion}' => trim($client->address.' '.$client->colonia.' '.$client->community),
            '{plan}' => $plan?->name ?? '',
            '{velocidad}' => $plan ? $plan->speedLabel().' / '.(float) $plan->upload_mbps.' Mbps' : '',
            '{periodicidad}' => mb_strtolower($plan?->periodicityLabel() ?? 'mensual'),
            '{precio}' => money($plan?->price ?? 0),
            '{tolerancia}' => $tenant->setting('billing.grace_days'),
            '{fecha}' => now()->translatedFormat('d \d\e F \d\e Y'),
        ];
        $body = strtr($tenant->setting('contract.template'), $vars);
        Audit::log('document', "generó contrato de cliente #{$client->code}", $client);

        return Pdf::loadView('pdf.contract', compact('client', 'tenant', 'body'))->stream("contrato-{$client->code}.pdf");
    }

    public function statement(Client $client)
    {
        $charges = $client->charges()->where('status', '!=', 'cancelled')->orderBy('due_date')->where('due_date', '>=', now()->subMonths(12))->get();
        $payments = $client->payments()->where('status', 'completed')->orderBy('paid_at')->where('paid_at', '>=', now()->subMonths(12))->get();

        return Pdf::loadView('pdf.statement', ['client' => $client, 'charges' => $charges, 'payments' => $payments, 'title' => 'Estado de cuenta'])
            ->stream("estado-cuenta-{$client->code}.pdf");
    }

    public function receipt(Payment $payment)
    {
        $payment->load('client.plan', 'allocations.charge', 'receivedBy', 'collector');

        return Pdf::loadView('pdf.receipt', ['payment' => $payment, 'title' => $payment->kind === 'credit' ? 'Nota de crédito' : 'Comprobante de pago'])
            ->setPaper([0, 0, 396, 612])->stream("comprobante-{$payment->folio}.pdf");
    }

    public function installation(InstallationOrder $order)
    {
        $order->load('client', 'prospect', 'plan', 'technician', 'repeater', 'attachments');

        return Pdf::loadView('pdf.installation', ['order' => $order, 'title' => 'Orden de instalación '.$order->folio])->stream("orden-{$order->folio}.pdf");
    }

    public function ticket(Ticket $ticket)
    {
        $ticket->load('client', 'technician', 'comments.user', 'attachments', 'materials.item');

        return Pdf::loadView('pdf.ticket', ['ticket' => $ticket, 'title' => 'Evidencia de servicio '.$ticket->folio])->stream("servicio-{$ticket->folio}.pdf");
    }
}
