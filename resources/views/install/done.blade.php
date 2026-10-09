<x-layouts.guest title="Instalación completada">
    <div class="mx-auto max-w-2xl p-6 py-12">
        <x-brand.logo :dark="false" size="lg" />
        <div class="card mt-8 p-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-success-600 text-white"><x-heroicon-s-check class="h-6 w-6" /></span>
                <h1 class="text-xl font-bold text-navy-900">Instalación completada</h1>
            </div>
            <p class="mt-4 text-sm text-slate-600">Ya puedes iniciar sesión con <b>{{ $email }}</b>.</p>
            <div class="mt-4 rounded-lg bg-warning-50 p-4 text-sm text-warning-700">
                <b>Importante:</b> edita el archivo <code>.env</code> y cambia <code>INSTALLER_ENABLED=false</code>. Después configura el cron de cPanel (ver docs/DESPLIEGUE.md).
            </div>
            <pre class="mt-4 max-h-60 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ implode("\n", $log) }}</pre>
            <x-ui.button :href="route('login')" :navigate="false" class="mt-6">Ir al inicio de sesión</x-ui.button>
        </div>
    </div>
</x-layouts.guest>
