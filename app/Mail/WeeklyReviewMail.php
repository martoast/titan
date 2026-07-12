<?php

namespace App\Mail;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The weekly review as an email -- week score + momentum, progress toward the dream physique (the north
 * star), wins, and what to change next week. Composed from the structured WeeklyReview::compile() array
 * (no AI call), framed in Titan branding. Mail failures are caught by the calling command.
 */
class WeeklyReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Profile $profile, public array $review)
    {
        // Render subject + chrome in the athlete's chosen language. (The metric labels/wins/headline
        // inside $review are already localized by WeeklyReview::compile() at build time.)
        $this->locale(\App\Support\Lang::locale($profile->primary_language));
    }

    public function envelope(): Envelope
    {
        $name = $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete');

        return new Envelope(subject: __('Titan · Your week in review, :name', ['name' => $name]));
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.url'), '/');

        return new Content(
            markdown: 'mail.coach.weekly-review',
            with: [
                'name' => $this->profile->display_name ?: ($this->profile->user?->name ?? 'athlete'),
                'review' => $this->review,
                'coachUrl' => $base.'/coach',
                'progressUrl' => $base.'/progress',
            ],
        );
    }
}
