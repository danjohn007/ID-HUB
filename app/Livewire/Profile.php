<?php

namespace App\Livewire;

use App\Livewire\Concerns\Toasts;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Perfil del usuario autenticado: nombre, teléfono y contraseña.
 */
#[Title('Mi perfil')]
class Profile extends Component
{
    use Toasts;

    public string $name = '';

    public string $phone = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $u = Auth::user();
        $this->name = (string) $u->name;
        $this->phone = (string) $u->phone;
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
        ], [], ['name' => 'nombre', 'phone' => 'teléfono']);
        Auth::user()->update(['name' => $data['name'], 'phone' => $data['phone'] ?: null]);
        $this->toast('Perfil actualizado');
    }

    public function savePassword(): void
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
        ], ['current_password' => 'contraseña actual', 'password' => 'nueva contraseña']);

        $user = Auth::user();
        $user->update(['password' => $this->password]);
        // Cierra las demás sesiones abiertas del usuario (control de sesiones)
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        }
        Audit::log('password_reset', 'cambió su contraseña', $user);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Contraseña actualizada. Se cerraron tus otras sesiones.');
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.profile', [
            'user' => $user,
            'activity' => \App\Models\AuditLog::withoutGlobalScopes()->where('user_id', $user->id)->latest('created_at')->limit(8)->get(),
        ]);
    }
}
