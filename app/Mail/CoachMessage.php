<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A one-off proactive coach message as an email -- the delivery channel for reactions (workout
 * celebration, morning sleep summary, recovery nudges) now that real push needs a paid Apple
 * account. Reuses the same Titan-branded markdown as the daily briefing; the notification title
 * becomes both the subject and the heading. Send failures are swallowed by NotificationService so
 * a single bad address never breaks the calling job. See {@see CoachBriefing} (the sibling used by
 * the scheduled morning/evening commands).
 */
class CoachMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Profile $profile,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Titan · '.$this->title);
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = $this->url ? '/'.ltrim($this->url, '/') : '/coach';

        return new Content(
            markdown: 'mail.coach.briefing',
            with: [
                'name' => $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete'),
                'body' => $this->body,
                'kind' => 'message',          // not morning/evening -> footer skips the cadence line
                'heading' => $this->title,
                'coachUrl' => $base.$path,
            ],
        );
    }
}
