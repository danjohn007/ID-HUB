@extends('public.pay.layout', ['pageTitle' => $status === 'approved' ? 'Pago recibido' : ($status === 'pending' ? 'Pago en proceso' : 'Pago no completado')])

@section('content')
    <div class="card p-6 text-center">
        @if ($status === 'approved' && $payment)
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success-50"><x-heroicon-s-check-circle class="h-11 w-11 text-success-600" /></span>
            <h1 class="mt-4 text-xl font-bold text-navy-900">¡Pago recibido!</h1>
            <p class="mt-1 text-sm text-slate-500">Gracias {{ $client?->first_name }}, tu pago fue aplicado a tu cuenta.</p>

            <dl class="mt-5 divide-y divide-slate-100 rounded-xl bg-slate-50 text-left text-sm">
                <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-slate-500">Importe</dt><dd class="font-bold text-navy-900">{{ money($payment->amount) }}</dd></div>
                <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-slate-500">Folio</dt><dd class="font-semibold text-slate-700">{{ $payment->folio }}</dd></div>
                <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-slate-500">Método</dt><dd class="text-slate-700">{{ $payment->methodLabel() }}</dd></div>
                <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-slate-500">Fecha</dt><dd class="text-slate-700">{{ $payment->paid_at->format('d/m/Y H:i') }}</dd></div>
                <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-slate-500">Saldo pendiente</dt><dd class="font-semibold {{ $client && $client->fresh()->balance > 0 ? 'text-danger-600' : 'text-success-700' }}">{{ money($client?->fresh()->balance ?? 0) }}</dd></div>
            </dl>

            <a href="{{ route('pay.receipt', [$link->token, $payment->id]) }}" target="_blank"
               class="pay-btn mt-5 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">
                <x-heroicon-o-document-arrow-down class="h-5 w-5" /> Descargar comprobante
            </a>
            @if ($client?->fresh()->service_status === 'activo')
                <p class="mt-3 text-xs text-success-700">Tu servicio está activo.</p>
            @endif
        @elseif ($status === 'pending')
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-warning-50"><x-heroicon-s-clock class="h-11 w-11 text-warning-600" /></span>
            <h1 class="mt-4 text-xl font-bold text-navy-900">Tu pago está en proceso</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($provider === 'oxxo')
                    Tu ficha OXXO fue generada. Realiza el pago en tienda; lo aplicaremos automáticamente en cuanto se confirme.
                @else
                    Estamos esperando la confirmación de la pasarela. Te enviaremos tu comprobante en cuanto se acredite.
                @endif
            </p>
            <a href="{{ route('pay.success', ['token' => $link->token, 'provider' => $provider]) }}" class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold pay-brand-text"><x-heroicon-o-arrow-path class="h-4 w-4" /> Actualizar estado</a>
        @else
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-danger-50"><x-heroicon-s-x-circle class="h-11 w-11 text-danger-600" /></span>
            <h1 class="mt-4 text-xl font-bold text-navy-900">El pago no se completó</h1>
            <p class="mt-1 text-sm text-slate-500">No se realizó ningún cargo. Puedes intentarlo de nuevo con el mismo u otro método.</p>
            @if ($link->isPayable())
                <a href="{{ route('pay.show', $link->token) }}" class="pay-btn mt-5 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">
                    <x-heroicon-o-arrow-uturn-left class="h-5 w-5" /> Intentar de nuevo
                </a>
            @endif
        @endif
    </div>
@endsection
