<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The payoff of a "take a reading now" → the coach interprets the live spot HRV the
 * instant the band's 60-second capture is processed. Posts a `spot` card (HRV + heart
 * rate vs the user's own baseline) and a push, so an on-demand reading feels like the
 * band answering directly. Unlike the daily recovery read, a spot reading is a momentary
 * snapshot -- it never overwrites the morning's overnight recovery.
 */
class ReactToSpotReading implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $profileId,
        public ?int $hrvMs = null,
        public ?int $restingHr = null,
        public bool $valid = true,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        // A noisy capture (motion artifact / no clean beats) → coach asks for a redo.
        if (! $this->valid || $this->hrvMs === null) {
            $body = "I couldn't get a clean read just now -- usually movement or a loose band. Sit still, keep it snug on the wrist, and ask me to take another.";
            \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
            $notifications->notify($profile, __('📍 Spot reading -- try again'), $body, '/coach', 'spot');
            $this->post($profile, "📍 **Spot reading -- couldn't lock on.**\n\n{$body}");

            return;
        }

        $baseline = $this->baselineHrv($profile->id);
        [$verdict, $label, $read] = $this->interpret($this->hrvMs, $baseline);

        $card = [
            'type' => 'spot',
            'hrv_ms' => $this->hrvMs,
            'resting_hr' => $this->restingHr,
            'baseline_ms' => $baseline,
            'verdict' => $verdict,
            'verdict_label' => $label,
        ];

        $line = "HRV {$this->hrvMs}ms".($this->restingHr ? ", heart rate {$this->restingHr}" : '')
            .($baseline ? " (baseline {$baseline}ms)" : '').' -- '.$read;

        \Illuminate\Support\Facades\App::setLocale(\App\Support\Lang::locale($profile->primary_language));
        $notifications->notify($profile, __('📍 Your spot reading is in'), $line, '/coach', 'spot');

        $fence = "```titan-card\n".json_encode($card)."\n```";
        $this->post($profile, "{$fence}\n\n📍 **Live reading:** {$line}");
    }

    /** The user's own resting HRV baseline -- median of the last 14 days of recovery logs. */
    private function baselineHrv(int $profileId): ?int
    {
        $vals = RecoveryLog::where('profile_id', $profileId)
            ->whereNotNull('hrv_ms')
            ->where('logged_at', '>=', Carbon::today()->subDays(14))
            ->orderByDesc('logged_at')
            ->pluck('hrv_ms')->all();
        if (count($vals) < 3) {
            return null;   // not enough history to compare against yet
        }
        sort($vals);
        $mid = intdiv(count($vals), 2);

        return (int) round(count($vals) % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2);
    }

    /**
     * Read the spot value against baseline. Without a baseline we describe the absolute
     * value plainly rather than guessing a verdict.
     *
     * @return array{0:string,1:string,2:string}  [verdict, label, plain-language read]
     */
    private function interpret(int $hrv, ?int $baseline): array
    {
        if (! $baseline) {
            return ['neutral', 'First readings', "I'll compare this to your baseline once I've gathered a few more days of data."];
        }
        $ratio = $hrv / $baseline;

        return match (true) {
            $ratio >= 1.08 => ['high', 'Above baseline', 'parasympathetic tone is high right now -- you\'re calm and well recovered.'],
            $ratio >= 0.92 => ['steady', 'Around baseline', 'right around your normal -- a balanced, steady state.'],
            $ratio >= 0.80 => ['low', 'Below baseline', 'a bit under your norm -- some fatigue or stress load; ease into anything hard.'],
            default => ['verylow', 'Well below baseline', 'notably suppressed -- your body\'s under real strain. Favour rest, hydration and a calm hour.'],
        };
    }

    private function post(Profile $profile, string $content): void
    {
        $convo = Conversation::forDay($profile);
        $convo->messages()->create(['role' => 'assistant', 'kind' => ChatMessage::KIND_REACTION, 'content' => $content]);
    }
}
