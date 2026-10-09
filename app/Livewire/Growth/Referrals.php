<?php

namespace App\Livewire\Growth;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Concerns\WithTable;
use App\Models\Client;
use App\Models\Prospect;
use App\Models\Referral;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Programa de referidos (sección 29): configuración, indicadores, validación y recompensa.
 */
#[Title('Referidos')]
class Referrals extends Component
{
    use Toasts, WithTable;

    #[Url(as: 'estado', except: '')]
    public string $filterStatus = '';

    public array $config = [];

    public function mount(): void
    {
        $cfg = tenant()->settingsFor('referrals');
        $this->config = [
            'enabled' => (bool) $cfg['enabled'], 'reward_type' => $cfg['reward_type'],
            'reward_value' => (string) (float) $cfg['reward_value'], 'code_prefix' => $cfg['code_prefix'],
        ];
    }

    public function saveConfig(): void
    {
        $this->validate([
            'config.enabled' => 'boolean',
            'config.reward_type' => ['required', Rule::in(array_keys(config('wisp.reward_types')))],
            'config.reward_value' => [Rule::requiredIf($this->config['reward_type'] !== 'mes_gratis'), 'nullable', 'numeric', 'min:0', 'max:100000'],
            'config.code_prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
        ], [], ['config.reward_type' => 'tipo de recompensa', 'config.reward_value' => 'valor', 'config.code_prefix' => 'prefijo']);

        tenant()->putSettings('referrals', [
            'enabled' => (bool) $this->config['enabled'],
            'reward_type' => $this->config['reward_type'],
            'reward_value' => (float) ($this->config['reward_value'] ?: 0),
            'code_prefix' => strtoupper($this->config['code_prefix']),
        ]);
        \App\Support\Audit::log('settings', 'actualizó el programa de referidos', tenant());
        $this->toast('Programa de referidos guardado');
    }

    public function validateAndReward(int $id, ReferralService $service): void
    {
        $r = Referral::with('referrer.plan', 'referred')->findOrFail($id);
        if ($r->status === 'recompensado') {
            $this->toast('Este referido ya fue recompensado', 'warning');

            return;
        }
        if (! $r->referrer) {
            $this->toast('El cliente que refirió ya no existe', 'error');

            return;
        }
        if ($r->status === 'pendiente') {
            $r->update(['status' => 'validado', 'validated_at' => now()]);
        }
        $service->reward($r->fresh());
        $r->refresh();
        $this->toast('Recompensa aplicada a '.$r->referrer->full_name.($r->reward_value > 0 ? ' por '.money($r->reward_value) : ''));
    }

    public function cancel(int $id): void
    {
        $r = Referral::findOrFail($id);
        abort_if($r->status === 'recompensado', 422);
        $r->update(['status' => 'cancelado']);
        $this->toast('Referido cancelado', 'warning');
    }

    protected function query()
    {
        return Referral::query()
            ->with(['referrer:id,code,first_name,last_name,referral_code,plan_id', 'referrer.plan:id,price', 'referred:id,code,first_name,last_name,service_status', 'prospect:id,name,status'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when(trim($this->search) !== '', function ($q) {
                $q->where(fn ($w) => $w->where('code', 'like', '%'.trim($this->search).'%')
                    ->orWhereHas('referrer', fn ($c) => $c->search($this->search))
                    ->orWhereHas('referred', fn ($c) => $c->search($this->search)));
            });
    }

    public function render()
    {
        $counts = Referral::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $rewarded = (float) Referral::where('status', 'recompensado')->sum('reward_value');
        $leads = Prospect::query()->where('source', 'referido')->count();
        $leadsOpen = Prospect::query()->where('source', 'referido')->whereNotIn('status', ['convertido', 'descartado'])->count();
        $withCode = Client::query()->whereNotNull('referral_code')->count();

        $top = Referral::query()->whereIn('status', ['pendiente', 'validado', 'recompensado'])
            ->select('referrer_client_id', DB::raw('COUNT(*) total'), DB::raw("SUM(CASE WHEN status = 'recompensado' THEN reward_value ELSE 0 END) earned"))
            ->groupBy('referrer_client_id')->orderByDesc('total')->limit(8)->get();
        $topClients = Client::whereIn('id', $top->pluck('referrer_client_id'))->get(['id', 'code', 'first_name', 'last_name', 'referral_code'])->keyBy('id');

        return view('livewire.growth.referrals', [
            'referrals' => $this->applySort($this->query(), 'created_at', 'desc')->paginate($this->perPage),
            'counts' => $counts,
            'kpi' => [
                'total' => $counts->sum(), 'pending' => (int) ($counts['pendiente'] ?? 0), 'validated' => (int) ($counts['validado'] ?? 0),
                'rewarded' => (int) ($counts['recompensado'] ?? 0), 'rewardedAmount' => $rewarded, 'leads' => $leads, 'leadsOpen' => $leadsOpen,
                'conversion' => $leads ? round(($counts->sum() - ($counts['cancelado'] ?? 0)) / max(1, $leads + $counts->sum()) * 100, 1) : null,
                'withCode' => $withCode,
            ],
            'top' => $top->map(fn ($t) => ['client' => $topClients[$t->referrer_client_id] ?? null, 'total' => (int) $t->total, 'earned' => (float) $t->earned])->filter(fn ($t) => $t['client']),
            'example' => strtoupper($this->config['code_prefix'] ?: 'INVITA').'-5821',
        ]);
    }
}
