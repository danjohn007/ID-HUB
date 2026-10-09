<div>
    <x-ui.page-header title="Mi perfil" subtitle="Tus datos de acceso y contacto" />

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <x-ui.card class="xl:row-span-2">
            <div class="flex flex-col items-center text-center">
                <x-ui.avatar :name="$user->name" size="xl" />
                <div class="mt-3 text-lg font-bold text-navy-900">{{ $user->name }}</div>
                <div class="text-sm text-slate-500">{{ $user->email }}</div>
                <x-ui.badge color="brand" class="mt-2">{{ $user->roleLabel() }}</x-ui.badge>
            </div>
            <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4">
                <x-ui.detail label="Empresa">{{ $user->tenant?->name ?? 'Plataforma SaaS' }}</x-ui.detail>
                <x-ui.detail label="Último acceso">{{ $user->last_login_at?->format('d/m/Y H:i') }}</x-ui.detail>
                <x-ui.detail label="IP del último acceso">{{ $user->last_login_ip }}</x-ui.detail>
                <x-ui.detail label="Alta">{{ $user->created_at?->format('d/m/Y') }}</x-ui.detail>
            </dl>
            @if ($activity->isNotEmpty())
                <div class="mt-5 border-t border-slate-100 pt-4">
                    <div class="mb-2 text-xs font-semibold text-slate-500">Tu actividad reciente</div>
                    <ul class="space-y-2 text-xs">
                        @foreach ($activity as $a)
                            <li class="flex justify-between gap-2"><span class="truncate text-slate-700">{{ ucfirst($a->description) }}</span><span class="shrink-0 text-slate-400">{{ $a->created_at?->format('d/m H:i') }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Datos personales" class="xl:col-span-2">
            <form wire:submit="saveProfile" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-ui.input label="Nombre" wire:model="name" required />
                <x-ui.input label="Teléfono" type="tel" wire:model="phone" />
                <x-ui.input label="Correo (usuario de acceso)" :value="$user->email" disabled hint="Solicita al administrador cambiar tu correo" class="md:col-span-2" />
                <div class="md:col-span-2"><x-ui.button type="submit" icon="check">Guardar datos</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card title="Cambiar contraseña" subtitle="Al cambiarla se cierran tus sesiones en otros dispositivos" class="xl:col-span-2">
            <form wire:submit="savePassword" class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-ui.input label="Contraseña actual" type="password" autocomplete="current-password" wire:model="current_password" required />
                <x-ui.input label="Nueva contraseña" type="password" autocomplete="new-password" wire:model="password" hint="Mínimo 8 caracteres con letras y números" required />
                <x-ui.input label="Confirmar nueva contraseña" type="password" autocomplete="new-password" wire:model="password_confirmation" required />
                <div class="md:col-span-3"><x-ui.button type="submit" icon="lock-closed">Actualizar contraseña</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
</div>
