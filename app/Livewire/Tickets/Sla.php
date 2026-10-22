<?php

namespace App\Livewire\Tickets;

use App\Livewire\Concerns\Toasts;
use App\Models\Alert;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Services\SlaMonitor;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Políticas de SLA (sección 22): tiempo objetivo y % de aviso por prioridad, y tiempo propio
 * opcional por tipo de incidencia (aplica el más estricto de los dos).
 */
#[Title('Políticas SLA')]
class Sla extends Component
{
    use Toasts;

    public array $policies = [];

    /** Horas por categoría ('' = solo la de la prioridad). */
    public array $categories = [];

    public function mount(): void
    {
        $existing = SlaPolicy::query()->whereNull('category')->get()->keyBy('priority');
        $byCategory = SlaPolicy::query()->whereNotNull('category')->pluck('resolution_minutes', 'category');
        foreach (array_keys(config('wisp.ticket_categories')) as $cat) {
            $this->categories[$cat] = isset($byCategory[$cat]) ? (string) round($byCategory[$cat] / 60, 2) : '';
        }
        foreach (config('wisp.ticket_priorities') as $key => $p) {
            $minutes = $existing[$key]->resolution_minutes ?? $p[2];
            $this->policies[$key] = [
                'hours' => (string) round($minutes / 60, 2),
                'warn' => (string) ($existing[$key]->warn_percent ?? 75),
            ];
        }
    }

    public function save(): void
    {
        $this->authorize('settings.manage');
        $rules = [];
        foreach (array_keys(config('wisp.ticket_priorities')) as $key) {
            $rules["policies.$key.hours"] = 'required|numeric|min:0.25|max:720';
            $rules["policies.$key.warn"] = 'required|integer|min:10|max:99';
        }
        foreach (array_keys(config('wisp.ticket_categories')) as $cat) {
            $rules["categories.$cat"] = 'nullable|numeric|min:0.25|max:720';
        }
        $this->validate($rules, [], collect($rules)->mapWithKeys(fn ($r, $k) => [$k => str_ends_with($k, 'hours') ? 'horas' : '% de aviso'])->all());

        foreach ($this->policies as $priority => $p) {
            SlaPolicy::updateOrCreate(['priority' => $priority, 'category' => null], [
                'resolution_minutes' => (int) round((float) $p['hours'] * 60),
                'warn_percent' => (int) $p['warn'],
            ]);
        }
        foreach ($this->categories as $cat => $hours) {
            $hours === '' || $hours === null
                ? SlaPolicy::query()->where('category', $cat)->delete()
                : SlaPolicy::updateOrCreate(['priority' => SlaPolicy::ANY_PRIORITY, 'category' => $cat], [
                    'resolution_minutes' => (int) round((float) $hours * 60), 'warn_percent' => 75,
                ]);
        }
        \App\Support\Audit::log('settings', 'actualizó las políticas de SLA');
        $this->toast('Políticas de SLA guardadas. Aplican a los tickets nuevos.');
    }

    public function recalculate(SlaMonitor $monitor): void
    {
        $this->authorize('settings.manage');
        $n = $monitor->recalculateOpen();
        $this->toast("Se recalculó el vencimiento de $n tickets abiertos");
    }

    public function runCheck(SlaMonitor $monitor): void
    {
        $this->authorize('settings.manage');
        $s = $monitor->check();
        $this->toast("Revisión completa: {$s['warned']} en riesgo, {$s['breached']} vencidos, {$s['resolved']} alertas resueltas");
    }

    public function render()
    {
        $from = now()->subDays(30);
        $stats = [];
        $now = now()->toDateTimeString();
        foreach (array_keys(config('wisp.ticket_priorities')) as $key) {
            $resolved = Ticket::query()->where('priority', $key)->whereNotNull('resolved_at')->where('resolved_at', '>=', $from);
            $n = (clone $resolved)->count();
            $stats[$key] = [
                'open' => Ticket::query()->open()->where('priority', $key)->count(),
                'overdue' => Ticket::query()->open()->where('priority', $key)->where('sla_due_at', '<', $now)->count(),
                'resolved' => $n,
                'pct' => $n ? round((clone $resolved)->where('sla_breached', false)->count() / $n * 100, 1) : null,
                'avg' => (float) (clone $resolved)->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) m')->value('m'),
            ];
        }

        return view('livewire.tickets.sla', [
            'stats' => $stats,
            'alerts' => Alert::query()->open()->whereIn('type', ['sla_risk', 'sla_breached'])->latest()->limit(8)->get(),
        ]);
    }
}
