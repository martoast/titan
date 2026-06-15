<?php

namespace App\Services\Physique;

use App\Exceptions\AiException;
use App\Models\LivingGoalRender;
use App\Models\PhysiqueAnalysis;
use App\Models\PhysiqueGoal;
use App\Models\Profile;
use App\Models\ProgressPhoto;
use App\Services\Ai\AiService;
use App\Services\Ai\NanoBananaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The living goal-physique loop — Titan's founding wedge.
 *
 * A believable dream-physique image that ADVANCES toward the goal as the user stays
 * consistent. Each weekly "step" re-renders the user's latest progress photo a calibrated,
 * identity-preserving increment toward their PhysiqueGoal image. The increment size is
 * driven by an adherence score (0..1) computed from how consistent their workouts, meals,
 * and recovery logging were over the prior ~2 weeks — slack, and the step is tiny; stay
 * the course, and the rendered "you" visibly gains ground. We keep a full history of renders
 * (LivingGoalRender) so the UI can show week-by-week progression.
 *
 * `compareToGoal()` is the "are you on track?" read: a vision comparison of the latest photo
 * to the goal image → {pct_to_goal, improved, lagging, summary}, saved as a PhysiqueAnalysis.
 *
 * Every AI/image path throws AiException on failure; callers (controller + command) catch it
 * and degrade gracefully so the page always renders.
 */
class LivingGoalService
{
    /** Adherence is scored over this trailing window (days). */
    private const WINDOW_DAYS = 14;

    /** Weekly targets used to normalise each adherence component to 0..1. */
    private const TARGET_WORKOUTS_PER_WEEK = 4;
    private const TARGET_MEAL_DAYS_PER_WEEK = 5;   // days with ≥1 logged meal
    private const TARGET_RECOVERY_DAYS_PER_WEEK = 5;

    /** A perfect week advances the picture at most this many % toward the goal. */
    private const MAX_STEP_PCT_PER_RENDER = 12;

    public function __construct(
        private readonly AiService $ai,
        private readonly NanoBananaClient $nano,
    ) {}

    /**
     * Render one step of the living goal image for a profile.
     *
     * Picks the latest progress photo + the active goal, scores recent adherence, decides how
     * big a step toward the goal that consistency has earned, asks Nano Banana to nudge the
     * current photo that far (identity-preserving), and persists the render as history.
     *
     * @return array{
     *     status:string,
     *     render?:LivingGoalRender,
     *     step_pct?:int,
     *     adherence?:float,
     *     breakdown?:array<string,float>,
     *     message?:string
     * }
     *
     * @throws AiException when image generation is unavailable/fails
     */
    public function renderProgressStep(Profile $profile): array
    {
        $goal = $this->activeGoal($profile);
        if (! $goal || ! $goal->goal_image_path) {
            return ['status' => 'no_goal', 'message' => 'No dream-physique goal yet.'];
        }

        $photo = $this->latestPhoto($profile);
        if (! $photo || ! $photo->photo_path) {
            return ['status' => 'no_photo', 'message' => 'No progress photo yet.'];
        }

        $breakdown = $this->adherenceBreakdown($profile);
        $adherence = $this->blendAdherence($breakdown);

        // Where the picture stood last time, and how far this week's consistency carries it.
        $previous = $this->latestRender($profile);
        $base = $previous?->step_pct ?? 0;
        $increment = (int) round($adherence * self::MAX_STEP_PCT_PER_RENDER);
        $stepPct = max($base, min(100, $base + $increment)); // monotonic: the picture never regresses below earned ground

        // A higher cumulative step => a more advanced rendered physique. Describe the increment
        // in plain language so the model nudges proportionally, not a full transformation.
        $intensity = $this->stepLanguage($adherence);

        $prompt = <<<PROMPT
        IMAGE 1 is this person's CURRENT progress photo. IMAGE 2 is their target "dream physique"
        goal image (the same person, fitter). Render a NEW photo of IMAGE 1's person taking ONE
        believable step toward IMAGE 2 — {$intensity}. They should look clearly a little closer to
        the goal than IMAGE 1 today, but this is a SINGLE realistic week-or-two of progress, never
        the full transformation. Keep their exact face, identity, skin tone, hair, pose, framing,
        lighting, and background from IMAGE 1 unchanged — unmistakably the SAME person, just a step
        further along. Photorealistic, natural, believable — not an exaggerated bodybuilder, not a
        fantasy filter. Currently about {$stepPct}% of the way from their starting point to the goal.
        PROMPT;

        $current = $this->nano->imageFromDisk($photo->photo_path);
        $target = $this->nano->imageFromDisk($goal->goal_image_path);
        $generated = $this->nano->generateToDisk($prompt, 'physique/living', [$current, $target]);

        $render = $profile->livingGoalRenders()->create([
            'physique_goal_id' => $goal->id,
            'progress_photo_id' => $photo->id,
            'image_path' => $generated['path'],
            'step_pct' => $stepPct,
            'adherence' => round($adherence, 3),
            'adherence_breakdown' => $breakdown,
        ]);

        // Mirror the newest render onto profile.settings so existing reads (and the old
        // controller path) keep showing the current living image without a query.
        $settings = $profile->settings ?? [];
        $settings['physique_living_image'] = $generated['path'];
        $profile->update(['settings' => $settings]);

        return [
            'status' => 'rendered',
            'render' => $render,
            'step_pct' => $stepPct,
            'adherence' => round($adherence, 3),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The "are you on track?" comparison. Vision-compares the latest progress photo to the
     * active goal image and records how far along the journey is, plus what's improved /
     * lagging, as a PhysiqueAnalysis. Supportive, confidence-aware, no medical claims.
     *
     * @return array{
     *     status:string,
     *     analysis?:PhysiqueAnalysis,
     *     pct_to_goal?:?int,
     *     improved?:array<int,string>,
     *     lagging?:array<int,string>,
     *     summary?:?string,
     *     message?:string
     * }
     *
     * @throws AiException when vision is unavailable/fails
     */
    public function compareToGoal(Profile $profile): array
    {
        $goal = $this->activeGoal($profile);
        if (! $goal || ! $goal->goal_image_path) {
            return ['status' => 'no_goal', 'message' => 'No dream-physique goal yet.'];
        }

        $photo = $this->latestPhoto($profile);
        if (! $photo || ! $photo->photo_path) {
            return ['status' => 'no_photo', 'message' => 'No progress photo yet.'];
        }

        $prompt = <<<PROMPT
        Two images of the SAME person: IMAGE 1 is their CURRENT progress photo, IMAGE 2 is their
        target "dream physique" goal image. Estimate how far along the journey they are from current
        to goal, and what's closing the gap versus what still has the most room. Reply with STRICT
        JSON only, exactly this shape:
        {
          "pct_to_goal": <integer 0-100>,
          "improved": ["<short phrase>", "<short phrase>"],
          "lagging": ["<short phrase>", "<short phrase>"],
          "summary": "<2-3 encouraging, realistic sentences narrating the progress and the next focus>"
        }
        Rules: be supportive and honest about uncertainty — this is a motivating physique read, not a
        measurement. Make NO medical claims or diagnoses, give NO health warnings. `improved` and
        `lagging` are 1-3 short phrases each (e.g. "shoulders", "leaner midsection"). Return ONLY the
        JSON object.
        PROMPT;

        $raw = $this->ai->vision(
            $prompt,
            [$this->dataUrl($photo->photo_path), $this->dataUrl($goal->goal_image_path)],
            ['json' => true, 'max_tokens' => 700],
        );

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new AiException('The goal comparison came back in an unexpected format.');
        }

        $pct = is_numeric($parsed['pct_to_goal'] ?? null)
            ? max(0, min(100, (int) round((float) $parsed['pct_to_goal'])))
            : null;

        $improved = $this->phraseList($parsed['improved'] ?? null);
        $lagging = $this->phraseList($parsed['lagging'] ?? null);
        $summaryText = is_string($parsed['summary'] ?? null) ? trim($parsed['summary']) : null;

        // Compose a single human-readable summary that captures the narrative + the deltas,
        // so the existing PhysiqueAnalysis surface renders it without schema changes.
        $bits = array_filter([
            $summaryText,
            $improved ? 'Improving: '.implode(', ', $improved).'.' : null,
            $lagging ? 'Focus next: '.implode(', ', $lagging).'.' : null,
        ]);

        $analysis = PhysiqueAnalysis::create([
            'profile_id' => $profile->id,
            'progress_photo_id' => $photo->id,
            'pct_to_goal' => $pct,
            'summary' => $bits ? implode(' ', $bits) : null,
        ]);

        return [
            'status' => 'compared',
            'analysis' => $analysis,
            'pct_to_goal' => $pct,
            'improved' => $improved,
            'lagging' => $lagging,
            'summary' => $summaryText,
        ];
    }

    // --- Adherence scoring -------------------------------------------------------------

    /**
     * Per-domain consistency over the trailing window, each normalised to 0..1 against a
     * weekly target. The window is ~2 weeks, so targets are scaled by WINDOW_DAYS/7.
     *
     * @return array{workouts:float,meals:float,recovery:float}
     */
    public function adherenceBreakdown(Profile $profile): array
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS)->startOfDay();
        $weeks = self::WINDOW_DAYS / 7;

        $workouts = $profile->workouts()->where('performed_at', '>=', $since)->count();

        // Distinct calendar days with at least one logged meal — credits the habit, not volume.
        $mealDays = $profile->meals()->where('eaten_at', '>=', $since)->get(['eaten_at'])
            ->map(fn ($m) => $m->eaten_at?->toDateString())->filter()->unique()->count();

        $recoveryDays = $profile->recoveryLogs()->where('logged_at', '>=', $since->toDateString())
            ->get(['logged_at'])->map(fn ($r) => $r->logged_at?->toDateString())
            ->filter()->unique()->count();

        return [
            'workouts' => $this->ratio($workouts, self::TARGET_WORKOUTS_PER_WEEK * $weeks),
            'meals' => $this->ratio($mealDays, self::TARGET_MEAL_DAYS_PER_WEEK * $weeks),
            'recovery' => $this->ratio($recoveryDays, self::TARGET_RECOVERY_DAYS_PER_WEEK * $weeks),
        ];
    }

    /**
     * Blend the per-domain scores into a single 0..1 adherence driver. Training is the
     * strongest physique lever, nutrition next, recovery-logging the lightest signal.
     *
     * @param  array{workouts:float,meals:float,recovery:float}  $breakdown
     */
    public function blendAdherence(array $breakdown): float
    {
        $score = $breakdown['workouts'] * 0.5
            + $breakdown['meals'] * 0.35
            + $breakdown['recovery'] * 0.15;

        return max(0.0, min(1.0, round($score, 3)));
    }

    /** Convenience: the single blended adherence score for a profile (0..1). */
    public function adherence(Profile $profile): float
    {
        return $this->blendAdherence($this->adherenceBreakdown($profile));
    }

    // --- Helpers -----------------------------------------------------------------------

    private function ratio(float|int $have, float $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, round($have / $target, 3)));
    }

    /** Translate adherence into the increment language the renderer should depict. */
    private function stepLanguage(float $adherence): string
    {
        return match (true) {
            $adherence >= 0.75 => 'a noticeable step: a bit more lean muscle fullness and sharper definition, earned by a strong, consistent stretch',
            $adherence >= 0.45 => 'a modest step: slightly more muscle tone and definition, reflecting steady-but-imperfect consistency',
            $adherence >= 0.15 => 'a small step: a barely-there improvement in tone, reflecting light, inconsistent effort',
            default => 'an almost imperceptible step: essentially holding the current physique, reflecting very little recent activity',
        };
    }

    /**
     * Coerce a vision "improved"/"lagging" field (array or comma string) into 1-3 clean phrases.
     *
     * @return array<int,string>
     */
    private function phraseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;]+/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_slice(
            array_map(fn ($v) => trim((string) $v), $value),
            0,
            3,
        )));
    }

    private function activeGoal(Profile $profile): ?PhysiqueGoal
    {
        return $profile->physiqueGoals()->where('is_active', true)->latest()->first()
            ?? $profile->physiqueGoals()->latest()->first();
    }

    private function latestPhoto(Profile $profile): ?ProgressPhoto
    {
        return $profile->progressPhotos()->orderByDesc('taken_at')->orderByDesc('id')->first();
    }

    private function latestRender(Profile $profile): ?LivingGoalRender
    {
        return $profile->livingGoalRenders()->latest()->first();
    }

    /** Encode a public-disk image as a data: URL for OpenAI vision input. */
    private function dataUrl(string $path): string
    {
        $bytes = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) $bytes);
    }
}
