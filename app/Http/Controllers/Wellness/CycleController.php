<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Support\Cycle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Menstrual-cycle tracking for the current profile: the cycle wheel + phase, predictions,
 * the estimated fertile window (awareness only), period/day logging, history, settings, and
 * the phase×recovery insight that ties the cycle to the rest of Titan.
 *
 * Wellness-only: nothing here is contraception or diagnosis -- the engine carries that rail.
 */
class CycleController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $status = Cycle::status($profile);
        $insight = Cycle::recoveryByPhase($profile);

        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();

        // Flo-style week strip + a 6-week projected calendar (grid-aligned to Sunday), each day
        // classified by phase so she can plan ahead. Only meaningful once a cycle is logged.
        $week = [];
        $calendarDays = [];
        if ($status['has_data'] ?? false) {
            $week = Cycle::calendar($profile, $today->copy()->startOfWeek(Carbon::SUNDAY), 7);
            $gridStart = $today->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY);
            $calendarDays = Cycle::calendar($profile, $gridStart, 42);
        }

        // Recent history: last 8 cycles, newest first, with their (known) lengths.
        $history = $profile->menstrualCycles()
            ->orderByDesc('start_date')->take(8)->get()
            ->map(fn ($c) => [
                'start' => $c->start_date->format('M j, Y'),
                'length' => $c->length_days,
                'period' => $c->period_end_date
                    ? $c->start_date->diffInDays($c->period_end_date) + 1
                    : null,
            ]);

        return view('cycle.index', [
            'profile' => $profile,
            'status' => $status,
            'insight' => $insight,
            'history' => $history,
            'week' => $week,
            'calendarDays' => $calendarDays,
            'monthLabel' => $today->format('F Y'),
            'monthNum' => $today->month,
            'symptoms' => Cycle::SYMPTOMS,
            'flows' => Cycle::FLOWS,
            'config' => $status['config'],
            'today' => $today->toDateString(),
        ]);
    }

    /** Log a period start (day 1) or end (last day of bleeding). */
    public function period(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate([
            'event' => ['required', 'in:start,end'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        if ($data['event'] === 'end') {
            Cycle::endPeriod($profile, Carbon::parse($data['date']));

            return redirect('/cycle')->with('status', 'Period end logged.');
        }

        Cycle::startPeriod($profile, Carbon::parse($data['date']));

        return redirect('/cycle')->with('status', 'Period start logged -- day 1.');
    }

    /** Log a cycle day: flow, symptoms, mood/energy, BBT. */
    public function logDay(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'flow' => ['nullable', 'in:none,spotting,light,medium,heavy'],
            'symptoms' => ['nullable', 'array'],
            'symptoms.*' => ['string'],
            'mood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'energy' => ['nullable', 'integer', 'min:1', 'max:5'],
            'bbt_c' => ['nullable', 'numeric', 'min:34', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Cycle::logDay($profile, Carbon::parse($data['date']), $data);

        return redirect('/cycle')->with('status', 'Cycle day logged.');
    }

    /** Save cycle settings (averages, birth control, intent, enable tracking). */
    public function settings(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'avg_length' => ['nullable', 'integer', 'min:21', 'max:45'],
            'avg_period' => ['nullable', 'integer', 'min:1', 'max:10'],
            'luteal_length' => ['nullable', 'integer', 'min:9', 'max:17'],
            'birth_control' => ['nullable', 'in:none,pill,patch,ring,hormonal_iud,copper_iud,implant,injection,other'],
            'intent' => ['nullable', 'in:tracking,conceiving,avoiding'],
        ]);

        $settings = $profile->settings ?? [];
        $settings['cycle'] = array_merge($settings['cycle'] ?? [], [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'avg_length' => $data['avg_length'] ?? ($settings['cycle']['avg_length'] ?? Cycle::DEFAULT_LENGTH),
            'avg_period' => $data['avg_period'] ?? ($settings['cycle']['avg_period'] ?? Cycle::DEFAULT_PERIOD),
            'luteal_length' => $data['luteal_length'] ?? ($settings['cycle']['luteal_length'] ?? Cycle::DEFAULT_LUTEAL),
            'birth_control' => $data['birth_control'] ?? ($settings['cycle']['birth_control'] ?? 'none'),
            'intent' => $data['intent'] ?? ($settings['cycle']['intent'] ?? 'tracking'),
        ]);
        $profile->update(['settings' => $settings]);

        return redirect('/cycle')->with('status', 'Cycle settings saved.');
    }
}
