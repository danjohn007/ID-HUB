<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demo agendada con un prospecto (agenda del CRM de ventas). */
class DemoMeeting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'booked_by_prospect' => 'boolean'];

    public const STATUSES = ['scheduled' => 'Agendada', 'done' => 'Realizada', 'no_show' => 'No se presentó', 'cancelled' => 'Cancelada'];

    public const STATUS_COLORS = ['scheduled' => 'purple', 'done' => 'success', 'no_show' => 'warning', 'cancelled' => 'slate'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class, 'demo_request_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Marca el resultado de la demo y avanza la etapa del prospecto cuando corresponde. */
    public function markAs(string $status): void
    {
        abort_unless(array_key_exists($status, self::STATUSES), 422);
        $this->update(['status' => $status]);
        $lead = $this->lead;
        $lead->log('meeting', 'Demo del '.$this->starts_at->translatedFormat('d M Y H:i').': '.mb_strtolower(self::STATUSES[$status]));
        if ($status === 'done' && in_array($lead->status, ['new', 'contacted', 'demo_scheduled'], true)) {
            $lead->changeStage('demo_done');
        }
    }

    public function title(): string
    {
        return 'Demo de '.config('wisp.brand.name', 'ID-Hub').' · '.($this->lead->company ?: $this->lead->name);
    }

    public function googleCalendarUrl(): string
    {
        $fmt = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->title(),
            'dates' => $fmt($this->starts_at).'/'.$fmt($this->endsAt()),
            'details' => trim(($this->meeting_url ? "Enlace: {$this->meeting_url}\n" : '').($this->lead->email ? "Prospecto: {$this->lead->name} <{$this->lead->email}>" : "Prospecto: {$this->lead->name}")),
            'location' => (string) $this->meeting_url,
        ]);
    }

    /** Archivo iCalendar (.ics) para agregar la demo a cualquier calendario. */
    public function ics(?string $summary = null): string
    {
        $fmt = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $esc = fn (?string $v) => str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string) $v);
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ID-Hub//Demos//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:demo-'.$this->id.'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'id-hub'),
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($this->starts_at),
            'DTEND:'.$fmt($this->endsAt()),
            'SUMMARY:'.$esc($summary ?? $this->title()),
            'DESCRIPTION:'.$esc($this->meeting_url ? "Enlace de la videollamada: {$this->meeting_url}" : 'Te compartiremos el enlace de la videollamada antes de la demo.'),
            'LOCATION:'.$esc($this->meeting_url),
            'STATUS:'.($this->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
