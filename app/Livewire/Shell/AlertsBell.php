<?php

namespace App\Livewire\Shell;

use App\Models\Alert;
use Livewire\Component;

class AlertsBell extends Component
{
    public function acknowledge(int $id): void
    {
        Alert::query()->whereKey($id)->update(['status' => 'acknowledged', 'acknowledged_by' => auth()->id()]);
    }

    public function render()
    {
        $query = Alert::query()->where('status', 'open');

        return view('livewire.shell.alerts-bell', [
            'count' => (clone $query)->count(),
            'alerts' => $query->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")->latest()->limit(8)->get(),
        ]);
    }
}
