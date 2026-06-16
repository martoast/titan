<?php

namespace App\Http\Controllers;

use App\Support\BiologicalAge;
use App\Support\DailyFocus;
use App\Support\MealCoach;
use App\Support\Readiness;
use App\Support\SleepCoach;
use App\Support\Strain;
use App\Support\StepGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The home dashboard — the emotional core, not a metrics dump. Three things, in order:
 *   1. Your FUTURE SELF — the living dream-physique render (it advances with adherence) + % to goal.
 *   2. Your TRAJECTORY — sparklines of the few metrics that show you're actually improving.
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

        // --- Trajectory: the few graphs that show real improvement ---
        $body = $p->bodyMetrics()->orderBy('taken_at')->get();
        $vo2 = $p->activitySessions()->whereNotNull('vo2max')->orderBy('started_at')->get();
        $bioAge = BiologicalAge::assess($p);

        $trajectories = array_values(array_filter([
            $this->series('Weight', $body->whereNotNull('weight_kg'), 'taken_at', 'weight_kg', 'kg', 'neutral'),
            $this->series('Body fat', $body->whereNotNull('body_fat_pct'), 'taken_at', 'body_fat_pct', '%', 'down'),
            $this->series('VO₂max', $vo2, 'started_at', 'vo2max', '', 'up'),
        ]));

        // --- The visual journey: recent progress photos ---
        $photos = $p->progressPhotos()->orderByDesc('taken_at')->orderByDesc('id')->take(6)->get()
            ->map(fn ($ph) => [
                'url' => $ph->photoUrl(),
                'date' => optional($ph->taken_at)->format('M j'),
                'weight' => $ph->weight_kg ? rtrim(rtrim(number_format((float) $ph->weight_kg, 1), '0'), '.').' kg' : null,
            ])->filter(fn ($x) => $x['url'])->values();

        // --- Cycle (only for women / those who track it) — phase-aware context tile ---
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
            'trajectories' => $trajectories,
            'photos' => $photos,
            'bioAge' => $bioAge,
        ]);
    }

    /**
     * Build a sparkline series from a model collection. `better` = which direction is improvement
     * ('up'/'down'/'neutral') → colours the delta. Returns null when there's nothing to plot.
     */
    private function series(string $label, $rows, string $dateKey, string $valKey, string $unit, string $better): ?array
    {
        $pts = $rows->map(fn ($r) => ['t' => optional($r->{$dateKey})->timestamp ?? 0, 'v' => (float) $r->{$valKey}])
            ->filter(fn ($x) => $x['v'] > 0)->sortBy('t')->values();
        if ($pts->count() < 2) {
            return null;
        }
        $first = $pts->first()['v'];
        $last = $pts->last()['v'];
        $delta = $last - $first;
        $improving = match ($better) {
            'up' => $delta > 0, 'down' => $delta < 0, default => null,
        };

        return [
            'label' => $label,
            'unit' => $unit,
            'current' => rtrim(rtrim(number_format($last, 1), '0'), '.'),
            'delta' => ($delta > 0 ? '+' : '').rtrim(rtrim(number_format($delta, 1), '0'), '.'),
            'improving' => $improving,
            'values' => $pts->pluck('v')->all(),
        ];
    }
}
