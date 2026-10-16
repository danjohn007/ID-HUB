<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SaasPlan;
use Database\Seeders\ProductionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Página pública de ID-Hub ("/"). Con sesión iniciada redirige al panel del usuario.
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

    /** Solicitud de demo: se envía por correo a wisp.brand.sales_email y queda copia en el log. */
    public function demo(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:160',
            'clients' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:1500',
            'website' => 'nullable|max:0', // trampa para bots (campo oculto)
        ], [], [
            'name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'company' => 'empresa',
            'clients' => 'número de clientes', 'message' => 'mensaje',
        ]);
        unset($data['website']);

        // Respaldo por si el servidor tiene en caché una configuración anterior sin sales_email
        $to = config('wisp.brand.sales_email') ?: 'dan@impactosdigitales.com';
        $body = implode("\n", [
            'Nueva solicitud de demo desde '.url('/'),
            '',
            'Nombre: '.$data['name'],
            'Correo: '.$data['email'],
            'Teléfono / WhatsApp: '.($data['phone'] ?: '—'),
            'Empresa / WISP: '.($data['company'] ?: '—'),
            'Clientes actuales: '.($data['clients'] ?: '—'),
            '',
            'Mensaje:',
            $data['message'] ?: '—',
            '',
            'Enviado: '.now()->format('d/m/Y H:i').' · IP '.$request->ip(),
        ]);
        Log::info('Solicitud de demo', $data);

        try {
            Mail::raw($body, fn ($mail) => $mail->to($to)->replyTo($data['email'], $data['name'])
                ->subject('Solicitud de demo · '.($data['company'] ?: $data['name'])));
        } catch (Throwable $e) {
            report($e);
            $error = "No pudimos enviar tu solicitud en este momento. Escríbenos directamente a {$to}.";

            return $request->expectsJson()
                ? response()->json(['message' => $error], 502)
                : back()->withInput()->withErrors(['demo' => $error]);
        }

        $ok = '¡Gracias! Recibimos tu solicitud y te contactaremos muy pronto.';

        return $request->expectsJson() ? response()->json(['message' => $ok]) : back()->with('demo_sent', $ok);
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
