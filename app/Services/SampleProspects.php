<?php

namespace App\Services;

use App\Models\DemoActivity;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prospectos de ejemplo para ver el CRM de ventas con movimiento (tablero, agenda y métricas).
 * Todos llevan correo @prospecto-demo.mx para poder borrarlos sin tocar los reales; no se envía ningún correo.
 */
class SampleProspects
{
    public const DOMAIN = 'prospecto-demo.mx';

    /** Etapa => cuántos prospectos de ejemplo. */
    protected const PLAN = [
        'new' => 6, 'contacted' => 7, 'demo_scheduled' => 6, 'demo_done' => 5,
        'trial' => 4, 'proposal' => 4, 'won' => 6, 'lost' => 7,
    ];

    protected const WISPS = [
        'Red Sierra Gorda', 'Conecta Norte', 'Wifi Pueblo', 'Aire Net', 'Onda Verde', 'NetCampo', 'Señal Huasteca', 'Enlace Bajío',
        'Fibra Valle', 'Vientos Net', 'Altiplano Wireless', 'Cumbre Net', 'TeleRancho', 'Internet del Lago', 'Cielo Conecta',
        'Red Magueyes', 'Antena Libre', 'Mixteca Online', 'Zacatecas Wifi', 'Costa Enlace', 'NetMontaña', 'Conexión Ejidal',
        'Raíces Net', 'Valle Digital', 'Puente Wireless', 'Nopal Net', 'Mezquite Online', 'Señal Serrana', 'Horizonte Wifi',
        'Llano Conecta', 'RedCañada', 'AlfaNet Rural', 'Volcán Wireless', 'Laguna Net', 'Pinos Conecta', 'Río Verde Online',
        'Jacal Net', 'Tierra Alta Wifi', 'Tuna Net', 'Encino Wireless', 'Agave Conecta', 'Rancho Net', 'Arroyo Wifi', 'Sotol Net', 'Cerro Azul Online',
    ];

    protected const FIRST = ['Juan', 'María', 'José', 'Guadalupe', 'Luis', 'Ana', 'Carlos', 'Laura', 'Miguel', 'Sofía', 'Jorge', 'Fernanda',
        'Ricardo', 'Patricia', 'Alejandro', 'Gabriela', 'Roberto', 'Daniela', 'Fernando', 'Mónica', 'Arturo', 'Verónica', 'Héctor', 'Claudia'];

    protected const LAST = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz',
        'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes', 'Jiménez', 'Torres', 'Díaz', 'Mendoza', 'Ruiz', 'Aguilar', 'Ortiz'];

    protected const PLACES = [
        ['Querétaro, Qro.', '442'], ['San Juan del Río, Qro.', '427'], ['Celaya, Gto.', '461'], ['León, Gto.', '477'], ['San Luis Potosí, S.L.P.', '444'],
        ['Pachuca, Hgo.', '771'], ['Huejutla, Hgo.', '789'], ['Morelia, Mich.', '443'], ['Zamora, Mich.', '351'], ['Tepatitlán, Jal.', '378'],
        ['Xalapa, Ver.', '228'], ['Puebla, Pue.', '222'], ['Oaxaca, Oax.', '951'], ['Tuxtla Gutiérrez, Chis.', '961'], ['Zacatecas, Zac.', '492'],
        ['Monterrey, N.L.', '81'], ['Aguascalientes, Ags.', '449'], ['Durango, Dgo.', '618'],
    ];

    protected const MESSAGES = [
        'Queremos automatizar los cortes por falta de pago en MikroTik.', 'Buscamos una app para que los técnicos registren instalaciones.',
        'Cobramos casa por casa y queremos control de cobradores.', 'Nos interesa que los clientes paguen en línea y por OXXO.',
        'Hoy llevamos todo en Excel y ya no nos da.', 'Queremos mandar recordatorios de pago por WhatsApp.',
        'Necesitamos ver en un mapa a los clientes y las torres.', 'Venimos de otro sistema y buscamos algo más completo.', '',
    ];

    protected const NOTES = [
        'call' => ['Le interesa la cobranza automática; pidió precio del plan Pro.', 'Tiene 3 torres y 2 técnicos, quiere la app de campo.',
            'Confirmó que usa MikroTik en todas sus torres.', 'Pidió que le llamemos después de las 5 pm.'],
        'whatsapp' => ['Le enviamos el folleto y la lista de precios.', 'Preguntó si se pueden migrar sus clientes desde Excel.',
            'Compartió fotos de su red; opera en 4 comunidades.', 'Quiere ver la demo con su socio.'],
        'email' => ['Enviamos la propuesta con el plan sugerido.', 'Mandamos el instructivo para cargar clientes en la prueba.'],
        'note' => ['Decide junto con su socio a fin de mes.', 'Le preocupa la conexión con sus routers antiguos.', 'Buen prospecto: crece rápido en su zona.'],
    ];

    public static function count(): int
    {
        return DemoRequest::where('email', 'like', '%@'.self::DOMAIN)->count();
    }

    /** Borra los prospectos de ejemplo (con su historial y demos). Devuelve cuántos se eliminaron. */
    public function clear(): int
    {
        return DB::transaction(fn () => DemoRequest::where('email', 'like', '%@'.self::DOMAIN)->get()->each->delete()->count());
    }

    /** Genera el set de ejemplo (reemplaza al anterior). Devuelve cuántos prospectos se crearon. */
    public function generate(): int
    {
        $this->clear();
        $users = User::whereNull('tenant_id')->where('role', 'superadmin')->where('active', true)->pluck('id')->all() ?: [null];
        $wisps = Arr::shuffle(self::WISPS);
        $order = array_flip(array_keys(DemoRequest::STAGES));
        $n = 0;

        DB::transaction(function () use ($users, $wisps, $order, &$n) {
            foreach (self::PLAN as $stage => $howMany) {
                for ($i = 0; $i < $howMany; $i++) {
                    $this->make($stage, $wisps[$n % count($wisps)], Arr::random($users), $order, $n);
                    $n++;
                }
            }
        });

        return $n;
    }

    protected function make(string $stage, string $wisp, ?int $userId, array $order, int $seq): void
    {
        [$place, $lada] = Arr::random(self::PLACES);
        $first = Arr::random(self::FIRST);
        $last = Arr::random(self::LAST);
        $clients = Arr::random(['Menos de 100', 'Menos de 100', '100 a 500', '100 a 500', '100 a 500', '500 a 2,000', 'Más de 2,000', 'Aún no opero']);
        $source = Arr::random(['landing', 'landing', 'landing', 'landing', 'whatsapp', 'whatsapp', 'referral', 'event', 'call', 'social']);

        // Antigüedad según la etapa: los nuevos son de estos días, los cerrados de semanas atrás
        $created = (match ($stage) {
            'new' => now()->subHours(rand(1, 96)),
            'contacted' => now()->subDays(rand(2, 12)),
            'demo_scheduled', 'demo_done' => now()->subDays(rand(5, 25)),
            'trial', 'proposal' => now()->subDays(rand(15, 45)),
            default => now()->subDays(rand(20, 120)),
        })->setSecond(0);
        $contacted = $stage === 'new' ? null : $created->copy()->addMinutes(rand(10, 60 * 30));

        $lead = DemoRequest::create([
            'name' => "{$first} {$last}",
            'email' => mb_strtolower(Str::ascii("{$first}.{$last}")).$seq.'@'.self::DOMAIN,
            'phone' => $lada.str_pad((string) rand(0, (int) str_repeat('9', 10 - strlen($lada))), 10 - strlen($lada), '0', STR_PAD_LEFT),
            'company' => $wisp,
            'location' => $place,
            'clients' => $clients,
            'clients_count' => rand(0, 2) ? null : (DemoRequest::CLIENT_RANGES[$clients] ?? 0) + rand(-20, 40),
            'current_software' => Arr::random(DemoRequest::SOFTWARE),
            'uses_mikrotik' => rand(0, 9) > 1,
            'message' => $source === 'landing' ? (Arr::random(self::MESSAGES) ?: null) : null,
            'source' => $source,
            'status' => $stage,
            'assigned_to' => $userId,
            'max_stage' => $stage === 'lost' ? rand(0, 3) : $order[$stage],
            'lost_reason' => $stage === 'lost' ? Arr::random(array_keys(DemoRequest::LOST_REASONS)) : null,
            'mail_sent' => true,
            'ip' => '0.0.0.0',
            'contacted_at' => $contacted,
            'stage_changed_at' => $stage === 'new' ? null : now()->subDays(rand(0, 6))->subHours(rand(0, 20)),
            'won_at' => $stage === 'won' ? now()->subDays(rand(1, 40)) : null,
            'lost_at' => $stage === 'lost' ? now()->subDays(rand(1, 50)) : null,
            'created_at' => $created,
            'updated_at' => now(),
        ]);
        if (in_array($stage, ['proposal', 'won', 'trial'], true) && $plan = $lead->planOrSuggested()) {
            $lead->update(['saas_plan_id' => $plan->id]);
        }

        // Historial
        $log = fn (string $type, string $body, Carbon $at, ?int $by = null) => DemoActivity::create([
            'demo_request_id' => $lead->id, 'user_id' => $by, 'type' => $type, 'body' => $body, 'created_at' => $at, 'updated_at' => $at,
        ]);
        if ($source !== 'landing') {
            $log('system', 'Prospecto capturado a mano · origen: '.DemoRequest::SOURCES[$source], $created, $userId);
        }
        if ($contacted) {
            $type = Arr::random(['call', 'whatsapp']);
            $log($type, Arr::random(self::NOTES[$type]), $contacted, $userId);
            $log('stage', 'Etapa: Nuevo → Contactado', $contacted, $userId);
        }

        // Demos
        $reached = (int) $lead->max_stage;
        if ($reached >= $order['demo_scheduled']) {
            $past = $reached >= $order['demo_done'] || $stage === 'lost';
            $start = $past
                ? $contacted->copy()->addDays(rand(1, 6))->setTime(Arr::random([10, 11, 12, 13, 16, 17]), 0)
                : now()->addDays(rand(0, 9))->setTime(Arr::random([10, 11, 12, 13, 16, 17]), 0);
            while ($start->isWeekend()) { // demos en días hábiles, como la autoagenda
                $past ? $start->subDay() : $start->addDay();
            }
            if (! $past && $start->isPast()) {
                $start->addDay();
            }
            if ($past && $start->isFuture()) {
                $start = now()->subDays(rand(1, 3))->setTime(11, 0);
            }
            $status = ! $past ? 'scheduled' : ($stage === 'lost' && $reached < $order['demo_done'] ? Arr::random(['no_show', 'cancelled']) : 'done');
            DemoMeeting::create([
                'demo_request_id' => $lead->id, 'starts_at' => $start, 'duration_minutes' => 45, 'assigned_to' => $userId,
                'status' => $status, 'booked_by_prospect' => $source === 'landing' && rand(0, 1),
                'meeting_url' => 'https://meet.google.com/demo-'.strtolower(Str::random(3)).'-'.strtolower(Str::random(4)),
                'reminder_sent_at' => $past ? $start->copy()->subDay() : null,
                'created_at' => $contacted, 'updated_at' => now(),
            ]);
            $log('meeting', 'Demo agendada: '.$start->translatedFormat('l j \d\e F, H:i'), $contacted->copy()->addHour(), $userId);
            if ($status !== 'scheduled') {
                $log('meeting', 'Demo del '.$start->translatedFormat('d M Y H:i').': '.mb_strtolower(DemoMeeting::STATUSES[$status]), $start->copy()->addHour(), $userId);
            }
        }
        if (in_array($stage, ['trial', 'proposal', 'won'], true)) {
            $log('email', Arr::random(self::NOTES['email']), now()->subDays(rand(1, 10)), $userId);
        }
        if (rand(0, 1)) {
            $log('note', Arr::random(self::NOTES['note']), now()->subDays(rand(0, 5)), $userId);
        }
        if ($stage === 'lost') {
            $log('stage', 'Etapa: '.DemoRequest::STAGES[array_keys(DemoRequest::STAGES)[$reached]].' → Perdido ('.DemoRequest::LOST_REASONS[$lead->lost_reason].')', $lead->lost_at, $userId);
        } elseif ($stage !== 'new' && $stage !== 'contacted') {
            $log('stage', 'Etapa: → '.DemoRequest::STAGES[$stage], $lead->stage_changed_at, $userId);
        }

        // Siguiente paso en los abiertos (algunos vencidos para ver la alerta)
        if ($lead->isOpen()) {
            $actions = [
                'new' => 'Primer contacto por WhatsApp', 'contacted' => 'Llamar para agendar la demo', 'demo_scheduled' => 'Confirmar asistencia a la demo',
                'demo_done' => 'Enviar propuesta y liga de prueba', 'trial' => 'Revisar avance de la prueba', 'proposal' => 'Dar seguimiento a la propuesta',
            ];
            $lead->update([
                'next_action' => $actions[$stage],
                'next_action_at' => rand(0, 3) ? now()->addHours(rand(2, 96))->setMinute(0) : now()->subHours(rand(2, 48))->setMinute(0),
            ]);
        }
    }
}
