<?php

namespace App\Http\Controllers\Duo;

use App\Http\Controllers\Controller;
use App\Services\Duo\DuoService;
use Illuminate\View\View;

/**
 * The brother-vs-brother duo dashboard at /duo. Renders a head-to-head
 * comparison, the physique race, current streaks, and this week's leaderboard.
 */
class DuoController extends Controller
{
    public function __construct(private readonly DuoService $duo)
    {
    }

    public function index(): View
    {
        // Ensure the current user has a profile (matches app convention),
        // but the dashboard itself compares the whole duo.
        auth()->user()?->ensureProfile();

        $comparison = $this->duo->comparison();
        $leaderboard = $this->duo->weeklyScores();

        $meId = auth()->user()?->profile?->id;

        return view('duo.index', [
            'comparison' => $comparison,
            'leaderboard' => $leaderboard,
            'meId' => $meId,
        ]);
    }
}
