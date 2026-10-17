<?php

namespace App\Mail;

use App\Models\DemoMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Confirmación, reprogramación o recordatorio de una demo, con invitación de calendario (.ics). */
class DemoMeetingMail extends Mailable
{
    use Queueable, SerializesModels;

    public const SUBJECTS = [
        'confirmation' => 'Tu demo de :brand quedó agendada',
        'rescheduled' => 'Reprogramamos tu demo de :brand',
        'reminder' => 'Recordatorio: tu demo de :brand es mañana',
    ];

    public function __construct(public DemoMeeting $meeting, public string $kind = 'confirmation') {}

    public function envelope(): Envelope
    {
        $brand = config('wisp.brand.name', 'ID-Hub');
        $subject = str_replace(':brand', $brand, self::SUBJECTS[$this->kind] ?? self::SUBJECTS['confirmation']);
        if ($this->kind === 'reminder' && ! $this->meeting->starts_at->isTomorrow()) {
            $subject = "Recordatorio: tu demo de {$brand} es ".($this->meeting->starts_at->isToday() ? 'hoy' : $this->meeting->starts_at->translatedFormat('l j'));
        }
        $sales = config('wisp.brand.sales_email');

        return new Envelope(subject: $subject, replyTo: $sales ? [$sales] : []);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.demo-meeting', with: [
            'brand' => config('wisp.brand.name', 'ID-Hub'),
            'lead' => $this->meeting->lead,
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->meeting->ics('Demo de '.config('wisp.brand.name', 'ID-Hub')), 'demo.ics')->withMime('text/calendar')];
    }
}
