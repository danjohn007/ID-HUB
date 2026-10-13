<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SaasPlan;
use Database\Seeders\ProductionSeeder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Página pública de WISPCONTROL ("/"). Con sesión iniciada redirige al panel del usuario.
 * Los planes salen de saas_plans (Panel SaaS); si la tabla aún no existe se usan los de ProductionSeeder.
 */
class LandingController extends Controller
{
    public function __invoke()
    {
        if (Auth::check()) {
            return redirect()->route(Auth::user()->homeRoute());
        }

        return view('public.landing', ['plans' => $this->plans()]);
    }

    protected function plans(): array
    {
        try {
            $plans = SaasPlan::where('active', true)->orderBy('sort')->get()->toArray();
        } catch (Throwable) {
            $plans = [];
        }
        $plans = $plans ?: ProductionSeeder::plans();

        return array_map(function (array $p) {
            $features = (array) ($p['features'] ?? []);
            $p['feature_labels'] = in_array('*', $features, true)
                ? array_values(SaasPlan::FEATURES)
                : array_values(array_intersect_key(SaasPlan::FEATURES, array_flip($features)));

            return $p;
        }, $plans);
    }
}
