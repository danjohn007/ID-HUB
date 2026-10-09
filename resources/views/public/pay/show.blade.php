@extends('public.pay.layout', ['pageTitle' => 'Pagar ahora'])

@section('content')
    @php
        $icons = ['mercadopago' => 'wallet', 'stripe' => 'credit-card', 'oxxo' => 'building-storefront', 'paypal' => 'globe-alt'];
        $hints = ['mercadopago' => 'Tarjeta, saldo Mercado Pago o efectivo', 'stripe' => 'Tarjeta de crédito o débito', 'oxxo' => 'Genera una ficha y paga en tienda OXXO', 'paypal' => 'Cuenta PayPal o tarjeta'];
    @endphp

    <div class="card overflow-hidden">
        <div class="p-5">
            <p class="text-sm text-slate-500">Hola,</p>
            <h1 class="text-xl font-bold text-navy-900">{{ $client?->full_name }}</h1>
            <p class="mt-0.5 text-xs text-slate-500">Cliente #{{ $client?->code }} @if ($client?->plan) · Plan {{ $client->plan->name }} @endif</p>

            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                <div class="text-xs font-medium text-slate-500">{{ $link->concept ?: 'Pago de servicio' }}</div>
                <div class="mt-1 text-3xl font-bold tracking-tight text-navy-900">{{ money($link->amount) }} <span class="text-sm font-medium text-slate-400">{{ $tenant->currency }}</span></div>
                @if ($charges->isNotEmpty())
                    <div class="mt-3 space-y-1.5 border-t border-slate-200 pt-3">
                        @foreach ($charges->take(5) as $ch)
                            <div class="flex items-center justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate text-slate-600">{{ $ch->description }}
                                    <span @class(['ml-1', 'text-danger-600 font-semibold' => $ch->isOverdue(), 'text-slate-400' => ! $ch->isOverdue()])>· vence {{ $ch->due_date->format('d/m/Y') }}</span></span>
                                <span class="font-semibold text-slate-700">{{ money($ch->balance()) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @if ($payment)
            <div class="border-t border-slate-100 bg-success-50 p-5 text-center">
                <x-heroicon-s-check-circle class="mx-auto h-10 w-10 text-success-600" />
                <p class="mt-2 font-semibold text-success-700">Este enlace ya fue pagado</p>
                <p class="text-xs text-slate-500">Folio {{ $payment->folio }} · {{ $payment->paid_at->format('d/m/Y H:i') }}</p>
                <a href="{{ route('pay.receipt', [$link->token, $payment->id]) }}" target="_blank" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold pay-brand-text"><x-heroicon-o-document-arrow-down class="h-4 w-4" /> Descargar comprobante</a>
            </div>
        @elseif (! $link->isPayable())
            <div class="border-t border-slate-100 bg-slate-50 p-5 text-center">
                <x-heroicon-o-clock class="mx-auto h-9 w-9 text-slate-400" />
                <p class="mt-2 font-semibold text-slate-700">Este enlace ya no está vigente</p>
                <p class="text-xs text-slate-500">Solicita un nuevo enlace de pago a {{ $tenant->name }}.</p>
            </div>
        @elseif ($gateways->isEmpty())
            <div class="border-t border-slate-100 bg-warning-50 p-5 text-center">
                <x-heroicon-o-exclamation-triangle class="mx-auto h-9 w-9 text-warning-600" />
                <p class="mt-2 font-semibold text-slate-700">Los pagos en línea no están disponibles por ahora</p>
                <p class="text-xs text-slate-500">Puedes pagar con tu cobrador o en nuestras oficinas.</p>
            </div>
        @else
            <form method="POST" action="{{ route('pay.checkout', $link->token) }}" class="border-t border-slate-100 p-5" onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.querySelector('span').textContent='Redirigiendo…';">
                @csrf
                <p class="mb-3 text-sm font-semibold text-slate-700">Elige cómo pagar</p>
                @if (session('pay_error'))
                    <div class="mb-3 rounded-lg bg-danger-50 px-3 py-2 text-xs font-medium text-danger-700">{{ session('pay_error') }}</div>
                @endif
                @error('provider')<div class="mb-3 rounded-lg bg-danger-50 px-3 py-2 text-xs font-medium text-danger-700">Selecciona un método de pago.</div>@enderror
                <div class="space-y-2">
                    @foreach ($gateways as $g)
                        <label class="pay-option flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3.5 transition">
                            <input type="radio" name="provider" value="{{ $g->provider }}" class="h-4 w-4" @checked($loop->first) required>
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600">
                                <x-dynamic-component :component="'heroicon-o-'.($icons[$g->provider] ?? 'credit-card')" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-slate-800">{{ config("wisp.gateways.{$g->provider}", $g->provider) }}</span>
                                <span class="block text-xs text-slate-500">{{ $hints[$g->provider] ?? '' }}</span>
                            </span>
                            @if ($service->isSimulated($g))
                                <span class="rounded-full bg-warning-50 px-2 py-0.5 text-[10px] font-semibold text-warning-700 ring-1 ring-warning-100">Prueba</span>
                            @endif
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="pay-btn mt-4 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-base font-bold text-white shadow-sm transition disabled:opacity-60">
                    <x-heroicon-s-lock-closed class="h-5 w-5" />
                    <span>Pagar {{ money($link->amount) }}</span>
                </button>
                @if ($link->expires_at)
                    <p class="mt-3 text-center text-xs text-slate-400">Enlace vigente hasta el {{ $link->expires_at->format('d/m/Y') }}</p>
                @endif
            </form>
        @endif
    </div>

    <p class="mt-4 text-center text-xs text-slate-400">Al confirmarse tu pago, tu servicio se reactiva automáticamente si estaba suspendido y recibirás tu comprobante.</p>
@endsection
