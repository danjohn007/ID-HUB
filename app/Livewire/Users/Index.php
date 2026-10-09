<?php

namespace App\Livewire\Users;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Usuarios del WISP y matriz de permisos por rol (sección 42).
 * Respeta el límite de usuarios del plan SaaS (sección 48).
 */
#[Title('Usuarios')]
class Index extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'rol', except: '')]
    public string $filterRole = '';

    #[Url(as: 'estado', except: '')]
    public string $filterActive = '';

    protected array $sortable = ['name', 'email', 'role', 'last_login_at', 'created_at'];

    public bool $showForm = false;

    public bool $showMatrix = false;

    public ?int $editingId = null;

    public array $form = [];

    /** Contraseña generada que se muestra una sola vez. */
    public ?string $generatedPassword = null;

    public ?string $generatedFor = null;

    public bool $showPassword = false;

    public static function roles(): array
    {
        return collect(config('wisp.roles'))->except('superadmin')->all();
    }

    protected function findUser(int $id): User
    {
        return User::forTenant()->where('role', '!=', 'superadmin')->findOrFail($id);
    }

    public function create(): void
    {
        if (! tenant()->withinLimit('users')) {
            $this->toast(tenant()->limitMessage('users'), 'error');

            return;
        }
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = ['name' => '', 'email' => '', 'phone' => '', 'role' => 'soporte', 'active' => true, 'on_call' => false, 'password' => '', 'generate' => true];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->resetErrorBag();
        $u = $this->findUser($id);
        $this->editingId = $u->id;
        $this->form = ['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role' => $u->role, 'active' => (bool) $u->active, 'on_call' => (bool) $u->on_call, 'password' => '', 'generate' => false];
        $this->showForm = true;
    }

    public function save(): void
    {
        $isNew = ! $this->editingId;
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.phone' => 'nullable|string|max:30',
            'form.role' => ['required', Rule::in(array_keys(self::roles()))],
            'form.active' => 'boolean',
            'form.on_call' => 'boolean',
            'form.password' => [$isNew && ! ($this->form['generate'] ?? false) ? 'required' : 'nullable', 'string', Password::min(8)->letters()->numbers()],
        ], [], ['form.name' => 'nombre', 'form.email' => 'correo', 'form.role' => 'rol', 'form.password' => 'contraseña', 'form.phone' => 'teléfono'])['form'];

        $me = auth()->user();
        $attrs = [
            'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?: null,
            'role' => $data['role'], 'active' => (bool) $data['active'], 'on_call' => (bool) $data['on_call'],
        ];

        if ($isNew) {
            if ($attrs['active'] && ! tenant()->withinLimit('users')) {
                $this->addError('form.active', tenant()->limitMessage('users'));

                return;
            }
            $password = ($this->form['generate'] ?? false) && ! $data['password'] ? $this->randomPassword() : $data['password'];
            $user = User::create($attrs + ['tenant_id' => tenant()->id, 'password' => $password]);
            if ($password !== $data['password']) {
                [$this->generatedPassword, $this->generatedFor, $this->showPassword] = [$password, $user->email, true];
            }
            $this->toast("Usuario {$user->name} creado");
        } else {
            $user = $this->findUser($this->editingId);
            if ($user->id === $me->id && (! $attrs['active'] || $attrs['role'] !== $user->role)) {
                $this->addError('form.role', 'No puedes desactivarte ni cambiar tu propio rol.');

                return;
            }
            if ($attrs['active'] && ! $user->active && ! tenant()->withinLimit('users')) {
                $this->addError('form.active', tenant()->limitMessage('users'));

                return;
            }
            if ($user->role === 'admin' && ($attrs['role'] !== 'admin' || ! $attrs['active']) && $this->activeAdmins() <= 1) {
                $this->addError('form.role', 'Debe quedar al menos un administrador activo.');

                return;
            }
            $user->update($attrs + ($data['password'] ? ['password' => $data['password']] : []));
            if ($data['password']) {
                Audit::log('password_reset', "cambió la contraseña de {$user->name}", $user);
            }
            $this->toast('Usuario actualizado');
        }
        $this->showForm = false;
    }

    protected function activeAdmins(): int
    {
        return User::forTenant()->active()->role('admin')->count();
    }

    public function toggleActive(int $id): void
    {
        $user = $this->findUser($id);
        if ($user->id === auth()->id()) {
            $this->toast('No puedes desactivar tu propio usuario.', 'error');

            return;
        }
        if (! $user->active && ! tenant()->withinLimit('users')) {
            $this->toast(tenant()->limitMessage('users'), 'error');

            return;
        }
        if ($user->active && $user->role === 'admin' && $this->activeAdmins() <= 1) {
            $this->toast('Debe quedar al menos un administrador activo.', 'error');

            return;
        }
        $user->update(['active' => ! $user->active]);
        Audit::log($user->active ? 'activate' : 'deactivate', ($user->active ? 'activó' : 'desactivó')." al usuario {$user->name}", $user);
        $this->toast($user->active ? 'Usuario activado' : 'Usuario desactivado; su sesión se cerrará en la siguiente petición.');
    }

    public function resetPassword(int $id): void
    {
        $user = $this->findUser($id);
        $password = $this->randomPassword();
        $user->update(['password' => $password, 'remember_token' => Str::random(60)]);
        Audit::log('password_reset', "restableció la contraseña de {$user->name}", $user);
        [$this->generatedPassword, $this->generatedFor, $this->showPassword] = [$password, $user->email, true];
    }

    public function toggleOnCall(int $id): void
    {
        $user = $this->findUser($id);
        $user->update(['on_call' => ! $user->on_call]);
        $this->toast($user->on_call ? "{$user->name} está de guardia" : "{$user->name} ya no está de guardia");
    }

    protected function randomPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /** Al cerrar el modal, la contraseña deja de viajar en el estado del componente. */
    public function updatedShowPassword($value): void
    {
        if (! $value) {
            $this->closePassword();
        }
    }

    public function closePassword(): void
    {
        $this->generatedPassword = null;
        $this->generatedFor = null;
        $this->showPassword = false;
    }

    public function render()
    {
        $tenant = tenant();
        $query = User::forTenant()->where('role', '!=', 'superadmin')
            ->when(trim($this->search), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('email', 'like', "%$t%")->orWhere('phone', 'like', "%$t%")))
            ->when($this->filterRole, fn ($q) => $q->where('role', $this->filterRole))
            ->when($this->filterActive !== '', fn ($q) => $q->where('active', $this->filterActive === '1'));

        return view('livewire.users.index', [
            'users' => $this->applySort($query, 'name', 'asc')->paginate($this->perPage),
            'roleCounts' => User::forTenant()->where('role', '!=', 'superadmin')->selectRaw('role, COUNT(*) n')->groupBy('role')->pluck('n', 'role'),
            'limit' => $tenant->planLimit('users'),
            'usage' => $tenant->usage('users'),
            'abilities' => config('permissions.abilities'),
            'matrix' => collect(self::roles())->map(fn ($label, $role) => config("permissions.roles.$role", []))->all(),
        ]);
    }
}
