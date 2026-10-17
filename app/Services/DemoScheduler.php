<?php

namespace App\Services;

use App\Mail\DemoMeetingMail;
use App\Models\DemoMeeting;
use App\Models\DemoRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Agenda de demos del CRM de ventas: horarios que se ofrecen en la página pública,
 * alta/reprogramación con correo de confirmación (.ics) y recordatorios del día anterior.
 */
class DemoScheduler
{
    /** Valores por omisión por si el servidor tiene en caché una configuración sin wisp.demos. */
    protected const DEFAULTS = [
        'self_booking' => true, 'duration' => 45, 'weekdays' => [1, 2, 3, 4, 5],
        'hours' => ['10:00', '11:00', '12:00', '13:00', '16:00', '17:00'],
        'horizon_days' => 14, 'min_notice_hours' => 18, 'meeting_url' => null, 'trial_days' => 14,
    ];

    public static function config(string $key): mixed
    {
        return config("wisp.demos.{$key}", self::DEFAULTS[$key] ?? null);
    }

    /**
     * Horarios libres para autoagendar, agrupados por día: ['2026-10-20' => [Carbon, ...]].
     *
     * @return Collection<string, Collection<int, Carbon>>
     */
    public function availableSlots(): Collection
    {
        try {
            $busy = DemoMeeting::where('status', 'scheduled')->where('starts_at', '>=', now()->subDay())
                ->get(['starts_at', 'duration_minutes']);
        } catch (Throwable) {
            return collect(); // migración pendiente
        }

        $duration = (int) self::config('duration');
        $earliest = now()->addHours((int) self::config('min_notice_hours'));
        $slots = collect();
        for ($d = 0; $d <= (int) self::config('horizon_days'); $d++) {
            $day = now()->startOfDay()->addDays($d);
            if (! in_array($day->dayOfWeekIso, (array) self::config('weekdays'), true)) {
                continue;
            }
            foreach ((array) self::config('hours') as $hour) {
                [$h, $m] = array_map('intval', explode(':', $hour) + [1 => 0]);
                $start = $day->copy()->setTime($h, $m);
                $end = $start->copy()->addMinutes($duration);
                $taken = $busy->contains(fn ($b) => $b->starts_at < $end && $b->starts_at->copy()->addMinutes($b->duration_minutes) > $start);
                if ($start >= $earliest && ! $taken) {
                    $slots->push($start);
                }
            }
        }

        return $slots->groupBy(fn (Carbon $s) => $s->toDateString());
    }

    public function isAvailable(Carbon $start): bool
    {
        return $this->availableSlots()->flatten()->contains(fn (Carbon $s) => $s->equalTo($start));
    }

    /** ¿Choca con otra demo agendada? (para avisar al capturar desde el panel). */
    public function conflict(Carbon $start, int $duration, ?int $ignoreId = null): ?DemoMeeting
    {
        $end = $start->copy()->addMinutes($duration);

        return DemoMeeting::with('lead')->where('status', 'scheduled')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereBetween('starts_at', [$start->copy()->subHours(4), $end])
            ->get()
            ->first(fn ($m) => $m->starts_at < $end && $m->endsAt() > $start);
    }

    /** Agenda (o reprograma) una demo, avanza la etapa y avisa al prospecto por correo. */
    public function schedule(DemoRequest $lead, Carbon $start, array $attrs = [], bool $notify = true, ?DemoMeeting $meeting = null): DemoMeeting
    {
        $rescheduled = $meeting && ! $meeting->starts_at->equalTo($start);
        $data = $attrs + [
            'starts_at' => $start,
            'duration_minutes' => (int) self::config('duration'),
            'meeting_url' => self::config('meeting_url'),
            'assigned_to' => $lead->assigned_to,
        ];
        // Si la demo es en menos de un día, la confirmación ya cumple como recordatorio
        $data['reminder_sent_at'] = $start->lessThan(now()->addDay()) ? now() : null;

        if ($meeting) {
            $meeting->update($data + ['status' => 'scheduled']);
        } else {
            $meeting = $lead->meetings()->create($data);
        }

        $when = $start->translatedFormat('l j \d\e F, H:i');
        $lead->log('meeting', ($meeting->wasRecentlyCreated ? 'Demo agendada' : ($rescheduled ? 'Demo reprogramada' : 'Demo actualizada')).": {$when}"
            .($meeting->booked_by_prospect ? ' (elegida por el prospecto)' : ''));
        if (in_array($lead->status, ['new', 'contacted'], true)) {
            $lead->changeStage('demo_scheduled');
        }
        if ($notify && ($meeting->wasRecentlyCreated || $rescheduled)) {
            $this->send($meeting, $rescheduled ? 'rescheduled' : 'confirmation');
        }

        return $meeting;
    }

    /** Correo al prospecto con la invitación .ics. Devuelve false si no tiene correo o falló el envío. */
    public function send(DemoMeeting $meeting, string $kind): bool
    {
        $email = $meeting->lead->email;
        if (! $email) {
            return false;
        }
        try {
            Mail::to($email, $meeting->lead->name)->send(new DemoMeetingMail($meeting, $kind));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Demos pendientes de hoy (insignia del menú). Cero si la migración aún no se ha aplicado. */
    public static function todayCount(): int
    {
        try {
            return DemoMeeting::where('status', 'scheduled')->whereBetween('starts_at', [now(), now()->endOfDay()])->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Recordatorio a las demos de las próximas 24 h que aún no lo recibieron (tarea programada). */
    public function sendReminders(): int
    {
        $sent = 0;
        DemoMeeting::with('lead')->where('status', 'scheduled')->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [now(), now()->addDay()])
            ->each(function (DemoMeeting $m) use (&$sent) {
                $m->update(['reminder_sent_at' => now()]);
                if ($this->send($m, 'reminder')) {
                    $m->lead->log('email', 'Recordatorio de la demo enviado a '.$m->lead->email);
                    $sent++;
                }
            });

        return $sent;
    }
}
