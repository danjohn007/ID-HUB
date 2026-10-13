<?php

namespace App\Livewire\Auth;

use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Iniciar sesión')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ], [], ['email' => 'correo', 'password' => 'contraseña']);

        $key = Str::lower($this->email).'|'.request()->ip();
        // El bloqueo se avisa con un evento: la vista lleva la cuenta regresiva segundo a segundo
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->dispatch('login-locked', seconds: RateLimiter::availableIn($key));

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'active' => true], $this->remember)) {
            RateLimiter::hit($key, 60);
            if (RateLimiter::tooManyAttempts($key, 5)) {
                $this->dispatch('login-locked', seconds: RateLimiter::availableIn($key));

                return;
            }
            throw ValidationException::withMessages(['email' => 'Las credenciales no son válidas.']);
        }

        RateLimiter::clear($key);
        session()->regenerate();
        session()->forget('acting_tenant_id');

        $user = Auth::user();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
        Audit::log('login', 'inició sesión', $user);

        return $this->redirectIntended(route($user->homeRoute()), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
