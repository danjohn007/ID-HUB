@extends('public.pay.layout', ['pageTitle' => 'Te invitaron'])

@section('content')
    @if (isset($registered))
        <div class="card p-6 text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-success-50"><x-heroicon-s-check-circle class="h-11 w-11 text-success-600" /></span>
            <h1 class="mt-4 text-xl font-bold text-navy-900">¡Gracias, {{ \Illuminate\Support\Str::before($registered->name, ' ') }}!</h1>
            <p class="mt-1 text-sm text-slate-500">Recibimos tus datos. Un asesor de {{ $tenant->name }} te contactará pronto para revisar la cobertura en tu domicilio.</p>
            @if ($tenant->whatsapp)
                <a href="https://wa.me/{{ preg_replace('/\D/', '', $tenant->whatsapp) }}?text={{ urlencode('Hola, me registré con el código '.$code.' y quiero contratar Internet.') }}" target="_blank" rel="noopener"
                   class="pay-btn mt-5 flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5" /> Escribir por WhatsApp
                </a>
            @endif
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="p-5">
                <p class="text-sm text-slate-500">{{ $referrer->first_name }} te invita a</p>
                <h1 class="text-xl font-bold text-navy-900">Contratar Internet con {{ $tenant->name }}</h1>
                <div class="mt-4 flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-4">
                    <div>
                        <div class="text-xs font-medium text-slate-500">Código de invitación</div>
                        <div class="mt-0.5 font-mono text-lg font-bold tracking-wider pay-brand-text">{{ $code }}</div>
                    </div>
                    <x-heroicon-o-gift class="h-9 w-9 text-slate-300" />
                </div>
                @if ($reward)
                    <p class="mt-3 text-xs text-slate-500">Al contratar y realizar tu primer pago, {{ $referrer->first_name }} recibe: <b class="text-slate-700">{{ $reward }}</b>.</p>
                @endif
            </div>

            @if ($plans->isNotEmpty())
                <div class="border-t border-slate-100 p-5">
                    <h2 class="text-sm font-semibold text-navy-900">Planes disponibles</h2>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @foreach ($plans as $p)
                            <div class="rounded-xl border border-slate-200 p-3">
                                <div class="text-sm font-semibold text-slate-800">{{ $p->name }}</div>
                                <div class="text-xs text-slate-500">{{ (float) $p->download_mbps }} Mbps</div>
                                <div class="mt-1 font-bold text-navy-900">{{ money($p->price, false) }}<span class="text-xs font-medium text-slate-400">/mes</span></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('referral.store', $code) }}" class="space-y-4 border-t border-slate-100 p-5">
                @csrf
                <h2 class="text-sm font-semibold text-navy-900">Déjanos tus datos</h2>
                <x-ui.input label="Nombre completo" name="name" :value="old('name')" required />
                <x-ui.input label="Teléfono / WhatsApp" name="phone" type="tel" :value="old('phone')" required />
                <x-ui.input label="Correo (opcional)" name="email" type="email" :value="old('email')" />
                <x-ui.input label="Domicilio donde quieres el servicio" name="address" :value="old('address')" hint="Calle, número, colonia y comunidad" required />
                @if ($plans->isNotEmpty())
                    <x-ui.select label="Plan de interés" name="desired_plan_id" :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.money($p->price, false)])->all()" placeholder="Aún no sé" />
                @endif
                <x-ui.textarea label="Comentarios (opcional)" name="notes" rows="2">{{ old('notes') }}</x-ui.textarea>
                <button type="submit" class="pay-btn flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white shadow-sm">
                    <x-heroicon-o-paper-airplane class="h-5 w-5" /> Quiero que me contacten
                </button>
            </form>
        </div>
    @endif
@endsection
