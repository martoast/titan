<?php

namespace App\Http\Controllers\Physique;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\PhysiqueAnalysis;
use App\Models\PhysiqueGoal;
use App\Models\ProgressPhoto;
use App\Services\Ai\AiService;
use App\Services\Ai\NanoBananaClient;
use App\Services\Physique\LivingGoalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The Physique vertical — the product's signature "wow" loop:
 *   1. Dream-physique generation: upload a photo -> Nano Banana renders the goal.
 *   2. Progress photos: dated gallery + side-by-side compare.
 *   3. AI physique analysis: vision -> BF% range + muscle ratings + summary.
 *   4. % to goal: vision compares latest photo to the goal image.
 *   5. Living goal image: nudge the latest progress photo one step toward the goal.
 *
 * Every AI/image action catches AiException and returns a friendly flash message so
 * the page renders even when the AI providers are offline or unconfigured.
 */
class PhysiqueController extends Controller
{
    public function __construct(
        private readonly AiService $ai,
        private readonly NanoBananaClient $nano,
        private readonly LivingGoalService $living,
    ) {}

    /** The hub: before/after, progress-to-goal bar, gallery, latest analysis. */
    public function index(): View
    {
        $profile = auth()->user()->ensureProfile();

        $goal = $profile->physiqueGoals()->where('is_active', true)->latest()->first()
            ?? $profile->physiqueGoals()->latest()->first();

        $photos = $profile->progressPhotos()->orderByDesc('taken_at')->orderByDesc('id')->get();

        $latestAnalysis = PhysiqueAnalysis::where('profile_id', $profile->id)
            ->latest()->first();

        // The living goal image: the latest "one step closer" render + the full week-by-week
        // history that powers the progression strip. Falls back to the legacy settings path.
        $livingRenders = $profile->livingGoalRenders()->latest()->take(8)->get();
        $latestRender = $livingRenders->first();
        $livingImagePath = $latestRender?->image_path
            ?? ($profile->settings['physique_living_image'] ?? null);
        $livingImageUrl = $livingImagePath ? Storage::disk('public')->url($livingImagePath) : null;

        return view('physique.index', [
            'profile' => $profile,
            'goal' => $goal,
            'photos' => $photos,
            'latestAnalysis' => $latestAnalysis,
            'livingImageUrl' => $livingImageUrl,
            'latestRender' => $latestRender,
            // Oldest → newest for a natural left-to-right progression strip.
            'livingRenders' => $livingRenders->reverse()->values(),
            'adherence' => $this->living->adherence($profile),
            'aiConfigured' => $this->ai->configured(),
            'imageGenConfigured' => $this->nano->configured(),
        ]);
    }

    /**
     * Feature 1 — Dream-physique generation. Upload a current photo, render the same
     * person with ~10 lbs more lean muscle (identity/face/lighting/background preserved).
     */
    public function generateGoal(Request $request): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();

        $data = $request->validate([
            'photo' => ['nullable', 'image', 'max:12288'],
            'source_photo_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:120'],
        ]);

        // Resolve the source image: a freshly uploaded photo, OR a progress photo the
        // user already has (so onboarding never asks for the same photo twice).
        if ($request->hasFile('photo')) {
            $sourcePath = $request->file('photo')->store('physique/source', 'public');
        } elseif (! empty($data['source_photo_id'])) {
            $existing = $profile->progressPhotos()->find($data['source_photo_id']);
            if (! $existing || ! $existing->photo_path) {
                return back()->with('error', "Couldn't find that photo — upload one and try again.");
            }
            $sourcePath = $existing->photo_path;
        } else {
            return back()->with('error', 'Add a photo first, then generate your dream physique.');
        }

        $description = $data['description'] ?: '+10 lbs lean muscle';

        $prompt = <<<PROMPT
        Take this person's photo and render them as their realistic future self after a
        dedicated period of training and nutrition: about 10 lbs more lean muscle, a
        leaner and more athletic, defined physique. Keep their exact face, identity,
        skin tone, hair, body proportions, pose, lighting, and background unchanged —
        this must look unmistakably like the SAME person, just fitter. Photorealistic,
        natural, believable — not an exaggerated bodybuilder, not a fantasy filter.
        Goal framing: {$description}.
        PROMPT;

        try {
            $input = $this->nano->imageFromDisk($sourcePath);
            $generated = $this->nano->generateToDisk($prompt, 'physique/goal', [$input]);
        } catch (AiException $e) {
            // Keep the source upload — let the user retry the render later.
            return back()->with('error', 'Could not generate your dream physique right now: '.$e->getMessage());
        }

        // New goal becomes the active one; retire previous goals.
        $profile->physiqueGoals()->update(['is_active' => false]);

        $profile->physiqueGoals()->create([
            'source_photo_path' => $sourcePath,
            'goal_image_path' => $generated['path'],
            'prompt' => $prompt,
            'description' => $description,
            'is_active' => true,
        ]);

        return back()->with('status', 'Your dream physique is ready. This is who you are becoming.');
    }

    /** Make a different goal the active one (e.g. revert to an earlier render). */
    public function activateGoal(PhysiqueGoal $goal): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();
        abort_unless($goal->profile_id === $profile->id, 403);

        $profile->physiqueGoals()->update(['is_active' => false]);
        $goal->update(['is_active' => true]);

        return back()->with('status', 'Goal image updated.');
    }

    /** Feature 2 — log a dated progress photo with pose + optional weight. */
    public function storePhoto(Request $request): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'taken_at' => ['nullable', 'date'],
            'pose' => ['nullable', 'in:front,side,back'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:600'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $path = $request->file('photo')->store('physique/progress', 'public');

        $profile->progressPhotos()->create([
            'photo_path' => $path,
            'taken_at' => $data['taken_at'] ?? now()->toDateString(),
            'pose' => $data['pose'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Progress photo added.');
    }

    public function destroyPhoto(ProgressPhoto $photo): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();
        abort_unless($photo->profile_id === $profile->id, 403);

        if ($photo->photo_path) {
            Storage::disk('public')->delete($photo->photo_path);
        }
        $photo->delete();

        return back()->with('status', 'Progress photo removed.');
    }

    /**
     * Feature 3 — AI physique analysis on a progress photo. Returns a strict-JSON read:
     * body-fat RANGE, per-muscle ratings (1-10), supportive non-medical summary.
     */
    public function analyze(ProgressPhoto $photo): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();
        abort_unless($photo->profile_id === $profile->id, 403);

        $url = $photo->photoUrl();
        if (! $url) {
            return back()->with('error', 'That photo is missing its image file.');
        }

        $prompt = <<<PROMPT
        You are a supportive, encouraging physique coach analyzing a fitness progress photo.
        Reply with STRICT JSON only, no prose, exactly this shape:
        {
          "body_fat_pct_low": <number>,
          "body_fat_pct_high": <number>,
          "muscle_ratings": {"chest":<1-10>,"back":<1-10>,"shoulders":<1-10>,"arms":<1-10>,"legs":<1-10>,"core":<1-10>},
          "summary": "<2-4 supportive sentences: strengths first, then the single highest-leverage focus area>"
        }
        Rules: body-fat MUST be a plausible RANGE (e.g. low 13, high 16) — never a single
        fake-precise number; keep the spread honest about uncertainty. Ratings are relative
        development, 1-10. Be encouraging and specific. Make NO medical claims or diagnoses,
        give NO health warnings — purely a motivating physique read. If a body part isn't
        visible, estimate conservatively. Return ONLY the JSON object.
        PROMPT;

        try {
            $raw = $this->ai->vision($prompt, [$this->dataUrl($photo->photo_path)], ['json' => true, 'max_tokens' => 700]);
        } catch (AiException $e) {
            return back()->with('error', 'Analysis is unavailable right now: '.$e->getMessage());
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            return back()->with('error', 'The analysis came back in an unexpected format. Please try again.');
        }

        [$low, $high] = $this->normalizeBfRange(
            $parsed['body_fat_pct_low'] ?? null,
            $parsed['body_fat_pct_high'] ?? null,
        );

        $ratings = [];
        foreach (PhysiqueAnalysis::MUSCLE_GROUPS as $group) {
            $val = $parsed['muscle_ratings'][$group] ?? null;
            if (is_numeric($val)) {
                $ratings[$group] = max(1, min(10, (int) round((float) $val)));
            }
        }

        PhysiqueAnalysis::create([
            'profile_id' => $profile->id,
            'progress_photo_id' => $photo->id,
            'body_fat_pct_low' => $low,
            'body_fat_pct_high' => $high,
            'muscle_ratings' => $ratings ?: null,
            'summary' => is_string($parsed['summary'] ?? null) ? trim($parsed['summary']) : null,
        ]);

        return back()->with('status', 'Physique analysis complete.');
    }

    /**
     * Feature 4 — % to goal. Compare the latest progress photo to the active goal image
     * and record how far along the journey is, plus what's improved / what's lagging.
     */
    public function compareToGoal(): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();

        try {
            $result = $this->living->compareToGoal($profile);
        } catch (AiException $e) {
            return back()->with('error', 'The goal comparison is unavailable right now: '.$e->getMessage());
        }

        return match ($result['status']) {
            'no_goal' => back()->with('error', 'Generate a dream-physique goal first.'),
            'no_photo' => back()->with('error', 'Add a progress photo first so we can measure the gap.'),
            default => back()->with('status', $result['pct_to_goal'] !== null
                ? "You're about {$result['pct_to_goal']}% of the way to your dream physique."
                : 'Goal comparison complete.'),
        };
    }

    /**
     * Feature 5 — the living goal image (the wedge). Re-render the user's latest progress
     * photo a calibrated single step toward the goal physique, and store it for display.
     */
    public function livingImage(): RedirectResponse
    {
        $profile = auth()->user()->ensureProfile();

        try {
            $result = $this->living->renderProgressStep($profile);
        } catch (AiException $e) {
            return back()->with('error', 'The living image render is unavailable right now: '.$e->getMessage());
        }

        return match ($result['status']) {
            'no_goal' => back()->with('error', 'Generate a dream-physique goal first.'),
            'no_photo' => back()->with('error', 'Add a progress photo first.'),
            default => back()->with('status', "One step closer. Your living goal image advanced to {$result['step_pct']}% toward your dream physique."),
        };
    }

    /** Encode a public-disk image as a data: URL for OpenAI vision input. */
    private function dataUrl(string $path): string
    {
        $bytes = Storage::disk('public')->get($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) $bytes);
    }

    /**
     * Force the body-fat estimate into a sane low<=high range, never a single number.
     *
     * @return array{0:?float,1:?float}
     */
    private function normalizeBfRange(mixed $low, mixed $high): array
    {
        $low = is_numeric($low) ? (float) $low : null;
        $high = is_numeric($high) ? (float) $high : null;

        if ($low === null && $high === null) {
            return [null, null];
        }
        // If only one bound came back, build a ~3-point window around it.
        if ($low === null) {
            $low = max(2, $high - 3);
        }
        if ($high === null) {
            $high = $low + 3;
        }
        if ($low > $high) {
            [$low, $high] = [$high, $low];
        }
        // Guarantee it reads as a range, not fake precision.
        if (abs($high - $low) < 0.5) {
            $high = $low + 2;
        }

        return [round($low, 1), round($high, 1)];
    }
}
