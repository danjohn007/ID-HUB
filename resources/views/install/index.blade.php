<x-layouts.guest title="Instalación">
    <div class="mx-auto max-w-2xl p-6 py-12">
        <x-brand.logo :dark="false" size="lg" />
        <h1 class="mt-8 text-2xl font-bold text-navy-900">Instalación de la plataforma</h1>
        <p class="mt-1 text-sm text-slate-500">Verifica los requisitos del servidor y crea la cuenta de superadministrador.</p>

        <div class="card mt-6 divide-y divide-slate-100">
            @foreach ($checks as $c)
                <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                    <span>{{ $c['label'] }} @unless ($c['required'])<span class="text-xs text-slate-400">(recomendado)</span>@endunless</span>
                    @if ($c['ok'])
                        <x-ui.badge color="success" dot>OK</x-ui.badge>
                    @else
                        <x-ui.badge :color="$c['required'] ? 'danger' : 'warning'" dot>{{ $c['required'] ? 'Falta' : 'Opcional' }}</x-ui.badge>
                    @endif
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ url('/install') }}" class="card mt-6 space-y-4 p-6">
            @csrf
            <h2 class="font-bold text-navy-900">Superadministrador</h2>
            <x-ui.input label="Nombre" name="name" :value="old('name', 'Superadministrador')" filter="name" required />
            <x-ui.input label="Correo" name="email" type="email" :value="old('email')" filter="email" required />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.input label="Contraseña (mín. 10)" name="password" type="password" maxlength="255" required />
                <x-ui.input label="Confirmar contraseña" name="password_confirmation" type="password" maxlength="255" required />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="demo" value="1" class="rounded border-slate-300"> Cargar datos de demostración (2 WISP ficticios)
            </label>
            <x-ui.button type="submit" class="w-full py-2.5">Instalar</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
