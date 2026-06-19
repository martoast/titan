<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The proactive coach briefing as an email (Mailgun SMTP is wired). Used for both the
 * morning briefing and the evening nudge -- `$kind` switches the subject + heading.
 *
 * The body text is already composed + grounded by CoachBriefingService; this Mailable
 * only frames it in Titan branding. Mail failures are caught by the calling command so a
 * single bad address never aborts the whole run.
 */
class CoachBriefing extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'morning'|'evening'  $kind
     */
    public function __construct(
        public Profile $profile,
        public string $body,
        public string $kind = 'morning',
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete');

        $subject = $this->kind === 'evening'
            ? "Titan · Evening check-in for {$name}"
            : "Titan · Your morning briefing, {$name}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.coach.briefing',
            with: [
                'name' => $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete'),
                'body' => $this->body,
                'kind' => $this->kind,
                'heading' => $this->kind === 'evening' ? 'Evening check-in' : 'Your morning briefing',
                'coachUrl' => rtrim((string) config('app.url'), '/').'/coach',
            ],
        );
    }
}
