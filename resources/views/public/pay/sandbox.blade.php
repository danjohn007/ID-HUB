@extends('public.pay.layout', ['pageTitle' => 'Checkout de prueba'])

@section('content')
    <div class="mb-4 flex items-start gap-2 rounded-xl border border-warning-100 bg-warning-50 px-4 py-3 text-xs text-warning-700">
        <x-heroicon-o-beaker class="mt-0.5 h-4 w-4 shrink-0" />
        <span><b>Modo de prueba.</b> Esta es una pasarela simulada de {{ config("wisp.gateways.$provider", $provider) }} (sandbox sin credenciales). No se realiza ningún cargo real.</span>
    </div>

    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <div class="text-xs text-slate-500">Pagar a</div>
                <div class="font-bold text-navy-900">{{ $tenant->name }}</div>
            </div>
            <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ config("wisp.gateways.$provider", $provider) }}</span>
        </div>
        <div class="p-5">
            <div class="text-xs text-slate-500">{{ $link->concept }}</div>
            <div class="mt-1 text-3xl font-bold text-navy-900">{{ money($link->amount) }}</div>
            <div class="mt-1 text-xs text-slate-500">{{ $client?->full_name }} · #{{ $client?->code }}</div>
            <div class="mt-4 rounded-xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">
                Tarjeta de prueba •••• 4242 · Comisión estimada {{ money($gateway->feeFor((float) $link->amount)) }}
            </div>
        </div>
        <form method="POST" action="{{ route('pay.sandbox.complete', [$link->token, $provider]) }}" class="grid grid-cols-2 gap-3 border-t border-slate-100 p-5">
            @csrf
            <button type="submit" name="decision" value="reject" class="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Rechazar</button>
            <button type="submit" name="decision" value="approve" class="rounded-xl bg-success-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-success-700">Aprobar pago</button>
        </form>
    </div>
@endsection
