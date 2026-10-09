<?php

namespace App\Livewire\Network;

use App\Livewire\Concerns\Toasts;
use App\Models\Repeater;
use App\Services\Network\BalancingService;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Balanceo de clientes (sección 13): detecta saturación y sugiere migraciones.
 * El administrador elige qué clientes migrar; nada se aplica automáticamente.
 */
#[Title('Balanceo de clientes')]
class Balancing extends Component
{
    use Toasts;

    /** Selección por sugerencia: key => [client ids] */
    public array $selected = [];

    /** Clientes mal distribuidos seleccionados: client id => repeater destino */
    public array $misplacedSelected = [];

    public bool $applyNetwork = true;

    public bool $showConfirm = false;

    public ?string $confirmKey = null;

    public function selectAll(string $key, array $ids): void
    {
        $this->selected[$key] = array_map('strval', $ids);
    }

    public function clearSelection(string $key): void
    {
        $this->selected[$key] = [];
    }

    public function confirm(string $key): void
    {
        $this->authorize('network.manage');
        $count = $key === 'misplaced' ? count(array_filter($this->misplacedSelected)) : count($this->selected[$key] ?? []);
        if (! $count) {
            $this->toast('Selecciona al menos un cliente', 'warning');

            return;
        }
        $this->confirmKey = $key;
        $this->showConfirm = true;
    }

    public function apply(BalancingService $balancing): void
    {
        $this->authorize('network.manage');
        $key = $this->confirmKey;
        $total = ['migrated' => 0, 'errors' => 0];

        if ($key === 'misplaced') {
            $targets = collect($balancing->analyze()['misplaced'])->pluck('to_id', 'id');
            $groups = collect($this->misplacedSelected)->filter()->keys()->filter(fn ($id) => $targets->has((int) $id))
                ->groupBy(fn ($id) => (int) $targets[(int) $id]);
            foreach ($groups as $targetId => $ids) {
                if ($target = Repeater::find($targetId)) {
                    $r = $balancing->migrate($ids->all(), $target, $this->applyNetwork, 'Cliente mal distribuido (balanceo)');
                    $total['migrated'] += $r['migrated'];
                    $total['errors'] += $r['errors'];
                }
            }
            $this->misplacedSelected = [];
        } elseif ($key && str_contains($key, '-')) {
            [, $to] = explode('-', $key, 2);
            $target = Repeater::findOrFail((int) $to);
            $total = $balancing->migrate($this->selected[$key] ?? [], $target, $this->applyNetwork, 'Balanceo por saturación');
            $this->selected[$key] = [];
        }

        $this->showConfirm = false;
        $this->confirmKey = null;
        $this->toast("{$total['migrated']} clientes migrados".($total['errors'] ? " · {$total['errors']} con error al aplicar en MikroTik" : ''), $total['errors'] ? 'warning' : 'success');
    }

    public function render(BalancingService $balancing)
    {
        $analysis = $balancing->analyze();
        foreach ($analysis['suggestions'] as $s) {
            if ($s['to'] && ! array_key_exists($s['key'], $this->selected)) {
                $this->selected[$s['key']] = collect($s['clients'])->pluck('id')->map(fn ($i) => (string) $i)->all();
            }
        }

        return view('livewire.network.balancing', ['a' => $analysis]);
    }
}
