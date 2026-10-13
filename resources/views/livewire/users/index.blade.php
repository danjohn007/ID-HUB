<div>
    <x-ui.page-header title="Usuarios" subtitle="Personal de tu WISP, roles y permisos">
        <x-ui.button variant="secondary" icon="table-cells" wire:click="$set('showMatrix', true)">Matriz de permisos</x-ui.button>
        <x-ui.button icon="plus" wire:click="create">Nuevo usuario</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_320px]">
        <div class="flex gap-2 overflow-x-auto pb-1">
            <button wire:click="$set('filterRole', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterRole === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterRole !== ''])>
                Todos <span class="ml-1 opacity-70">{{ $roleCounts->sum() }}</span>
            </button>
            @foreach (\App\Livewire\Users\Index::roles() as $key => $label)
                <button wire:click="$set('filterRole', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterRole === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterRole !== $key])>
                    {{ $label }} <span class="ml-1 opacity-70">{{ $roleCounts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        <div class="card px-4 py-3">
            <div class="mb-1 flex justify-between text-xs"><span class="font-medium text-slate-500">Usuarios activos del plan</span>
                <span class="font-bold text-navy-900">{{ $usage }} / {{ $limit ?? '∞' }}</span></div>
            <x-ui.progress :value="$limit ? $usage / $limit * 100 : 0" />
        </div>
    </div>

    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 gap-3 border-b border-slate-100 p-4 md:grid-cols-4">
            <x-ui.search wire:model.live.debounce.300ms="search" placeholder="Nombre, correo o teléfono..." class="md:col-span-3" />
            <x-ui.select wire:model.live="filterActive" :options="['1' => 'Activos', '0' => 'Inactivos']" placeholder="Todos los estados" />
        </div>
        <x-ui.table>
            <x-slot:head>
                <x-ui.th sort="name" :sort-by="$sortBy" :sort-dir="$sortDir">Usuario</x-ui.th>
                <x-ui.th sort="role" :sort-by="$sortBy" :sort-dir="$sortDir">Rol</x-ui.th>
                <th>Teléfono</th>
                <th>Guardia</th>
                <th>Estado</th>
                <x-ui.th sort="last_login_at" :sort-by="$sortBy" :sort-dir="$sortDir">Último acceso</x-ui.th>
                <th></th>
            </x-slot:head>
            @forelse ($users as $u)
                <tr wire:key="u{{ $u->id }}" @class(['opacity-60' => ! $u->active])>
                    <td>
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$u->name" />
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-800">{{ $u->name }} @if ($u->id === auth()->id())<x-ui.badge color="brand" size="xs">Tú</x-ui.badge>@endif</div>
                                <div class="text-xs text-slate-500">{{ $u->email }}</div>
                            </div>
                        </div>
                    </td>
                    <td><x-ui.badge :color="['admin' => 'navy', 'finanzas' => 'purple', 'soporte' => 'brand', 'tecnico' => 'teal', 'cobrador' => 'success', 'supervisor' => 'warning'][$u->role] ?? 'slate'">{{ $u->roleLabel() }}</x-ui.badge></td>
                    <td class="text-sm text-slate-600">{{ $u->phone ?: '—' }}</td>
                    <td>
                        @if (in_array($u->role, ['tecnico', 'supervisor']))
                            <button type="button" wire:click="toggleOnCall({{ $u->id }})" title="Cambiar guardia">
                                <x-ui.badge :color="$u->on_call ? 'warning' : 'slate'" :dot="$u->on_call">{{ $u->on_call ? 'De guardia' : 'No' }}</x-ui.badge>
                            </button>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    <td><x-ui.badge :color="$u->active ? 'success' : 'slate'" dot>{{ $u->active ? 'Activo' : 'Inactivo' }}</x-ui.badge></td>
                    <td class="text-xs text-slate-500">{{ $u->last_login_at ? $u->last_login_at->format('d/m/Y H:i') : 'Nunca' }}@if ($u->last_login_ip)<div class="text-slate-400">{{ $u->last_login_ip }}</div>@endif</td>
                    <td class="text-right whitespace-nowrap">
                        <x-ui.button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $u->id }})" title="Editar" />
                        <x-ui.button variant="ghost" size="sm" icon="key" wire:click="resetPassword({{ $u->id }})" wire:confirm="¿Generar una contraseña nueva para {{ $u->name }}?" title="Restablecer contraseña" />
                        @if ($u->id !== auth()->id())
                            @if ($u->active)
                                <x-ui.button variant="ghost" size="sm" icon="no-symbol" class="text-danger-600" wire:click="toggleActive({{ $u->id }})" wire:confirm="¿Desactivar a {{ $u->name }}? No podrá iniciar sesión." title="Desactivar" />
                            @else
                                <x-ui.button variant="ghost" size="sm" icon="check-circle" class="text-success-600" wire:click="toggleActive({{ $u->id }})" title="Activar" />
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :colspan="7" icon="users" title="Sin usuarios" description="Ajusta los filtros o crea un usuario." />
            @endforelse
        </x-ui.table>
        <div class="border-t border-slate-100 px-4 py-3">{{ $users->links() }}</div>
    </x-ui.card>

    {{-- Alta / edición --}}
    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar usuario' : 'Nuevo usuario'" size="xl">
        <form wire:submit="save" id="user-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.input label="Nombre completo" wire:model="form.name" filter="name" required class="sm:col-span-2" />
            <x-ui.input label="Correo (usuario de acceso)" type="email" wire:model="form.email" filter="email" required />
            <x-ui.input label="Teléfono" type="tel" wire:model="form.phone" filter="phone" />
            <x-ui.select label="Rol" wire:model.live="form.role" :options="\App\Livewire\Users\Index::roles()" required class="sm:col-span-2" />
            @if (! empty($form['role']))
                <div class="sm:col-span-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    @php $perms = config('permissions.roles.'.$form['role'], []); @endphp
                    <span class="font-semibold">Permisos:</span>
                    {{ in_array('*', $perms) ? 'Acceso total a la empresa' : collect($perms)->map(fn ($p) => config("permissions.abilities.$p", $p))->implode(' · ') }}
                </div>
            @endif
            @if (! $editingId)
                <x-ui.toggle wire:model.live="form.generate" label="Generar contraseña segura" description="Se mostrará una sola vez" class="sm:col-span-2" />
            @endif
            @if ($editingId || ! ($form['generate'] ?? false))
                <x-ui.input :label="$editingId ? 'Nueva contraseña (opcional)' : 'Contraseña'" type="password" autocomplete="new-password" wire:model="form.password" maxlength="100" hint="Mínimo 8 caracteres con letras y números" class="sm:col-span-2" />
            @endif
            <x-ui.toggle wire:model="form.active" label="Usuario activo" description="Los usuarios inactivos no pueden iniciar sesión" />
            @if (in_array($form['role'] ?? '', ['tecnico', 'supervisor']))
                <x-ui.toggle wire:model="form.on_call" label="Técnico de guardia" description="Recibe tickets automáticos por fallas" />
            @endif
            @error('form.active')<p class="sm:col-span-2 text-xs font-medium text-danger-600">{{ $message }}</p>@enderror
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="user-form" icon="check">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Contraseña generada --}}
    <x-ui.modal wire:model="showPassword" title="Contraseña generada" size="md">
        <p class="text-sm text-slate-600">Comparte esta contraseña de forma segura con <b>{{ $generatedFor }}</b>. No se volverá a mostrar.</p>
        <div class="mt-3 flex items-center gap-2" x-data="{ copied: false }">
            <input readonly value="{{ $generatedPassword }}" x-ref="pw" class="form-input font-mono text-base tracking-wider">
            <x-ui.button variant="secondary" icon="clipboard" x-on:click="navigator.clipboard?.writeText($refs.pw.value); copied = true"><span x-text="copied ? 'Copiada' : 'Copiar'"></span></x-ui.button>
        </div>
        <x-slot:footer><x-ui.button wire:click="closePassword">Listo</x-ui.button></x-slot:footer>
    </x-ui.modal>

    {{-- Matriz de permisos (solo lectura) --}}
    <x-ui.modal wire:model="showMatrix" title="Matriz de permisos por rol" size="5xl">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-left">
                        <th class="py-2 pr-3 font-semibold text-slate-500">Permiso</th>
                        @foreach (\App\Livewire\Users\Index::roles() as $role => $label)
                            <th class="px-2 py-2 text-center font-semibold text-slate-700">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($abilities as $ability => $label)
                        <tr>
                            <td class="py-1.5 pr-3 text-slate-700">{{ $label }} <span class="font-mono text-[10px] text-slate-400">{{ $ability }}</span></td>
                            @foreach ($matrix as $role => $perms)
                                <td class="px-2 py-1.5 text-center">
                                    @if (in_array('*', $perms) || in_array($ability, $perms))
                                        <x-heroicon-s-check-circle class="mx-auto h-4 w-4 text-success-600" />
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-500">La matriz se define a nivel plataforma. El superadministrador SaaS tiene acceso total.</p>
    </x-ui.modal>
</div>
