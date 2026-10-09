<?php

namespace App\Livewire\Concerns;

/**
 * $this->toast('Cliente guardado');  $this->toast('Error', 'error');
 * Tipos: success | error | warning | info
 */
trait Toasts
{
    public function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }
}
