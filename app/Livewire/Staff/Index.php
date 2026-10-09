<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\Toasts;
use App\Models\User;
use App\Services\Field\StaffMetrics;
use App\Support\Audit;
use App\Support\MapData;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Técnicos y cobradores: carga de trabajo, ubicación, desempeño y guardia.
 */
#[Title('Técnicos y cobradores')]
class Index extends Component
{
    use Toasts;

    #[Url(as: 'rol')]
    public string $role = 'tecnico';

    #[Url(as: 'periodo')]
    public string $period = 'mes';

    public string $search = '';

    public function updatedRole(): void
    {
        $this->role = in_array($this->role, ['tecnico', 'cobrador'], true) ? $this->role : 'tecnico';
        $this->dispatch('map-data-staff-map', data: ['markers' => $this->markers(), 'fit' => true]);
    }

    public function toggleOnCall(int $id): void
    {
        $this->authorize('tickets.manage');
        $user = User::forTenant()->role('tecnico')->findOrFail($id);
        $value = ! $user->on_call;
        if ($value) {
            // Un solo técnico de guardia por WISP
            User::forTenant()->where('on_call', true)->where('id', '!=', $user->id)->update(['on_call' => false]);
        }
        $user->forceFill(['on_call' => $value])->save();
        Audit::log('on_call', ($value ? 'asignó guardia a ' : 'retiró guardia a ').$user->name, $user);
        $this->toast($value ? "{$user->name} es ahora el técnico de guardia" : "{$user->name} ya no está de guardia", $value ? 'success' : 'warning');
    }

    protected function markers(): array
    {
        $type = $this->role === 'tecnico' ? 'technician' : 'collector';

        return array_values(array_filter(MapData::staff(), fn ($m) => $m['type'] === $type));
    }

    public function render(StaffMetrics $metrics)
    {
        $range = StaffMetrics::range($this->period);
        $users = User::forTenant()->role($this->role)->orderByDesc('active')->orderBy('name')
            ->when(trim($this->search), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('email', 'like', '%'.trim($this->search).'%')))
            ->get();
        $stats = $this->role === 'tecnico' ? $metrics->technicians($users->pluck('id'), $range) : $metrics->collectors($users->pluck('id'), $range);

        return view('livewire.staff.index', [
            'users' => $users,
            'stats' => $stats,
            'counts' => User::forTenant()->active()->role(['tecnico', 'cobrador'])->selectRaw('role, COUNT(*) n')->groupBy('role')->pluck('n', 'role'),
            'markers' => $this->markers(),
            'totals' => [
                'a' => collect($stats)->sum($this->role === 'tecnico' ? 'open' : 'collected'),
                'b' => collect($stats)->sum($this->role === 'tecnico' ? 'resolved' : 'visits'),
                'c' => collect($stats)->sum($this->role === 'tecnico' ? 'installs' : 'pending_amount'),
                'stops' => collect($stats)->sum('stops'),
                'done' => collect($stats)->sum('stops_done'),
            ],
        ]);
    }
}
