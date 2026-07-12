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
    ) {
        // Render the whole email (subject + body chrome) in the athlete's chosen language.
        $this->locale(\App\Support\Lang::locale($profile->primary_language));
    }

    public function envelope(): Envelope
    {
        $name = $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete');

        $subject = $this->kind === 'evening'
            ? __('Titan · Evening check-in for :name', ['name' => $name])
            : __('Titan · Your morning briefing, :name', ['name' => $name]);

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
                'heading' => $this->kind === 'evening' ? __('Evening check-in') : __('Your morning briefing'),
                'coachUrl' => rtrim((string) config('app.url'), '/').'/coach',
            ],
        );
    }
}
