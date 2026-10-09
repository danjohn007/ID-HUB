<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Prospect;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Página pública del enlace de referido (sección 29). Sin autenticación:
 * el WISP se resuelve a partir del código del cliente que comparte el enlace.
 */
class ReferralController extends Controller
{
    protected function resolve(string $code): Client
    {
        $referrer = Client::query()->with('tenant')->where('referral_code', strtoupper(trim($code)))->first();
        abort_unless($referrer && $referrer->tenant?->isActive() && $referrer->tenant->setting('referrals.enabled', true), 404);
        app(TenantContext::class)->set($referrer->tenant);

        return $referrer;
    }

    protected function data(Client $referrer): array
    {
        $cfg = $referrer->tenant->settingsFor('referrals');

        return [
            'tenant' => $referrer->tenant,
            'referrer' => $referrer,
            'code' => $referrer->referral_code,
            'reward' => config('wisp.reward_types.'.$cfg['reward_type']),
            'plans' => Plan::query()->where('active', true)->where('public', true)->orderBy('price')->get(),
            'headerSubtitle' => 'Invitación de '.$referrer->first_name,
        ];
    }

    public function show(string $code)
    {
        return view('public.referral.show', $this->data($this->resolve($code)));
    }

    public function store(Request $request, string $code)
    {
        $referrer = $this->resolve($code);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'address' => 'required|string|max:190',
            'desired_plan_id' => ['nullable', Rule::exists('plans', 'id')->where('tenant_id', $referrer->tenant_id)->where('active', true)],
            'notes' => 'nullable|string|max:500',
        ], [], ['name' => 'nombre', 'phone' => 'teléfono', 'email' => 'correo', 'address' => 'domicilio', 'desired_plan_id' => 'plan', 'notes' => 'comentarios']);

        $prospect = Prospect::create($data + [
            'whatsapp' => $data['phone'],
            'source' => 'referido',
            'status' => 'nuevo',
            'referral_code' => $referrer->referral_code,
        ]);
        Audit::log('created', "registró al prospecto {$prospect->name} desde el enlace de referido de {$referrer->first_name}", $prospect);

        return view('public.referral.show', $this->data($referrer) + ['registered' => $prospect]);
    }
}
