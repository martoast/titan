<?php

namespace App\Http\Controllers;

use App\Support\BiologicalAge;
use App\Support\DailyFocus;
use App\Support\Hydration;
use App\Support\Macros;
use App\Support\MealCoach;
use App\Support\Readiness;
use App\Support\SleepCoach;
use App\Support\Strain;
use App\Support\StepGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The home dashboard -- the emotional core, not a metrics dump. Three things, in order:
 *   1. Your FUTURE SELF -- the living dream-physique render (it advances with adherence) + % to goal.
 *   2. Your TRAJECTORY -- sparklines of the few metrics that show you're actually improving.
 *   3. Your daily pulse + the visual journey (recent progress photos).
 * Everything degrades gracefully to a gentle call-to-action when the data isn't there yet.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $p = $request->user()->ensureProfile();

        // --- Future self: the living goal render (morphs with adherence), then goal image, then CTA ---
        $render = $p->livingGoalRenders()->latest()->first();
        $goal = $p->physiqueGoals()->where('is_active', true)->latest()->first()
            ?? $p->physiqueGoals()->latest()->first();
        $latestPhoto = $p->progressPhotos()->orderByDesc('taken_at')->orderByDesc('id')->first();

        $pct = $render?->step_pct
            ?? optional(\App\Models\PhysiqueAnalysis::where('profile_id', $p->id)->latest()->first())->pct_to_goal;
        $futureSelf = [
            'image' => $render?->imageUrl() ?? $goal?->goalUrl(),
            'now_image' => $latestPhoto?->photoUrl() ?? $goal?->sourceUrl(),
            'pct' => $pct !== null ? (int) $pct : null,
            'adherence' => $render?->adherence !== null ? (int) round($render->adherence * 100) : null,
            'has_goal' => (bool) $goal,
        ];

        // --- The daily loop: Recovery → Strain → Sleep, plus the one thing to work on ---
        $rec = $p->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $readiness = Readiness::compute($p, $rec?->logged_at);
        $focus = DailyFocus::compute($p);
        $strain = Strain::assess($p);
        $sleepCoach = SleepCoach::assess($p);
        $steps = (int) ($p->dailyActivity()->whereDate('date', Carbon::today())->value('steps') ?? 0);
        $today = [
            'readiness' => $readiness['score'] ?? null,
            'readiness_label' => $readiness['label'] ?? '',
            'from_wearable' => str_starts_with((string) $rec?->updated_via, 'biosignal'),
            'steps' => $steps,
            'step_goal' => StepGoal::assess($steps, StepGoal::targetFor($p)),
        ];

        // --- The long game: one motivating biological-age stat. The deep longitudinal
        //     review (weight / body-fat / VO₂ trends + photo journey) lives on /progress,
        //     so the home stays a calm "today" screen instead of a metrics dump. ---
        $bioAge = BiologicalAge::assess($p);

        // --- Cycle (only for women / those who track it) -- phase-aware context tile ---
        $cycle = null;
        if (\App\Support\Cycle::available($p)) {
            $cs = \App\Support\Cycle::status($p);
            if ($cs['has_data'] ?? false) {
                $cycle = $cs;
            }
        }

        return view('dashboard', [
            'profile' => $p,
            'name' => $request->user()->name,
            'futureSelf' => $futureSelf,
            'today' => $today,
            'focus' => $focus,
            'cycle' => $cycle,
            'strain' => $strain,
            'sleepCoach' => $sleepCoach,
            'meal' => MealCoach::assess($p),
            'macros' => Macros::forHome($p),
            'hydration' => Hydration::today($p),
            'bioAge' => $bioAge,
        ]);
    }
}
