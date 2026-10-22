<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DemoRequest;
use App\Models\SaasPlan;
use App\Services\DemoScheduler;
use Carbon\Carbon;
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
        // En el dominio de un WISP no se muestra la página comercial de la plataforma.
        if (\App\Models\Tenant::forHost(request()->getHost())) {
            return redirect()->route('login');
        }

        return view('public.landing', [
            'plans' => $this->plans(),
            'demoSlots' => DemoScheduler::config('self_booking') ? app(DemoScheduler::class)->availableSlots() : collect(),
        ]);
    }

    /**
     * Solicitud de demo: queda registrada para el panel del superadministrador (Solicitudes de demo)
     * y se envía por correo a wisp.brand.sales_email. Basta con que una de las dos vías funcione.
     */
    public function demo(Request $request, DemoScheduler $scheduler)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => 'nullable|string|max:30',
            'company' => 'nullable|string|max:160',
            'clients' => 'nullable|string|max:30',
            'message' => 'nullable|string|max:1500',
            'slot' => 'nullable|date_format:Y-m-d H:i', // horario elegido para la demo (autoagenda)
            'website' => 'nullable|max:0', // trampa para bots (campo oculto)
        ], [], [
            'name' => 'nombre', 'email' => 'correo', 'phone' => 'teléfono', 'company' => 'empresa',
            'clients' => 'número de clientes', 'message' => 'mensaje', 'slot' => 'horario',
        ]);
        $slot = ! empty($data['slot']) && DemoScheduler::config('self_booking') ? Carbon::createFromFormat('Y-m-d H:i', $data['slot']) : null;
        if ($slot && ! $scheduler->isAvailable($slot)) {
            $msg = 'Ese horario acaba de ocuparse. Elige otro, por favor.';

            return $request->expectsJson()
                ? response()->json(['message' => $msg, 'errors' => ['slot' => [$msg]]], 422)
                : back()->withInput()->withErrors(['slot' => $msg]);
        }
        unset($data['website'], $data['slot']);
        $data += ['phone' => null, 'company' => null, 'clients' => null, 'message' => null]; // opcionales que no llegaron

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
            'Demo: '.($slot ? 'agendada por el prospecto para el '.$slot->translatedFormat('l j \d\e F, H:i').' h' : 'sin horario elegido'),
            '',
            'Mensaje:',
            $data['message'] ?: '—',
            '',
            'Enviado: '.now()->format('d/m/Y H:i').' · IP '.$request->ip(),
        ]);
        Log::info('Solicitud de demo', $data);

        try {
            $record = DemoRequest::create($data + ['ip' => $request->ip()]);
        } catch (Throwable $e) {
            report($e); // p. ej. migración pendiente: el correo sigue saliendo
            $record = null;
        }

        $meeting = null;
        if ($record && $slot) {
            try {
                $meeting = $scheduler->schedule($record, $slot, ['booked_by_prospect' => true]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            Mail::raw($body, fn ($mail) => $mail->to($to)->replyTo($data['email'], $data['name'])
                ->subject('Solicitud de demo · '.($data['company'] ?: $data['name'])));
            $record?->update(['mail_sent' => true]);
            $mailed = true;
        } catch (Throwable $e) {
            report($e);
            $mailed = false;
        }

        if (! $record && ! $mailed) {
            $error = "No pudimos enviar tu solicitud en este momento. Escríbenos directamente a {$to}.";

            return $request->expectsJson()
                ? response()->json(['message' => $error], 502)
                : back()->withInput()->withErrors(['demo' => $error]);
        }

        $ok = $meeting
            ? '¡Listo! Tu demo quedó agendada para el '.$slot->translatedFormat('l j \d\e F').' a las '.$slot->format('H:i').' h.'
                .($meeting->lead->email ? ' Te enviamos la confirmación a tu correo.' : '')
            : '¡Gracias! Recibimos tu solicitud y te contactaremos muy pronto.';

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
