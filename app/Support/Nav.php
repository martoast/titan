<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Single source of truth for the app's navigation — shared by the chat drawer
 * (components/chat-shell) and the standard layout's bottom bar + "More" sheet
 * (components/titan-layout). Before this, each layout carried its own flat list
 * of ~16 destinations; they drifted and overwhelmed. Here the same destinations
 * are grouped into four scannable sections, with cycle gated to the right users.
 *
 * Icons are raw SVG `path` `d` strings (Heroicons-style), drawn at 24×24.
 */
class Nav
{
    /** The four daily tabs pinned to the mobile bottom bar (most-used, in order). */
    public const PRIMARY = ['dashboard', 'meals', 'workouts', 'coach'];

    /**
     * Grouped navigation. Returns an ordered map of section label => items, with
     * cycle-only destinations filtered out for users who don't track a cycle.
     *
     * @return array<string, list<array{label:string, path:string, icon:string}>>
     */
    public static function groups(?Profile $profile = null): array
    {
        $showCycle = $profile ? Cycle::available($profile) : false;

        $groups = [
            // The everyday loop — what a user opens Titan to do.
            'Daily' => [
                self::item('Coach', 'coach', 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'),
                self::item('Dashboard', 'dashboard', 'M3 12l2-2 7-7 7 7 2 2M5 10v10a1 1 0 001 1h3m10-11v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'),
                self::item('Meals', 'meals', 'M5 3v7a3 3 0 006 0V3M8 3v18m9-18s2 1 2 5-2 4-2 4v7'),
                self::item('Workouts', 'workouts', 'M6.5 6.5l11 11M4 9l1.5-1.5M9 4L7.5 5.5m9 13L18 17m-1-9l2-2M2.5 11.5l3 3m13-3l-3-3'),
            ],
            // Your body's signals — recovery, sleep, movement, cycle.
            'Body' => array_values(array_filter([
                self::item('Recovery', 'recovery', 'M13 10V3L4 14h7v7l9-11h-7z'),
                self::item('Sleep', 'sleep', 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'),
                self::item('Fitness', 'fitness', 'M3 12h3l2-7 4 14 2-7h7'),
                $showCycle ? self::item('Cycle', 'cycle', 'M21 12a9 9 0 11-2.64-6.36M21 4v4h-4') : null,
            ])),
            // The journey — where you're headed and how far you've come.
            'Progress' => [
                self::item('Progress', 'progress', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'),
                self::item('Bloodwork', 'biomarkers', 'M12 3s5 5.5 5 9.5a5 5 0 11-10 0C7 8.5 12 3 12 3z'),
            ],
            // Knowledge & setup — the deeper library, touched less often.
            'Library' => [
                self::item('Foods', 'foods', 'M4 6h16M4 10h16M4 14h10M4 18h10'),
                self::item('The Brain', 'brain', 'M9.5 4a2.5 2.5 0 00-2.45 3A2.5 2.5 0 005 9.5a2.5 2.5 0 001.5 2.29M9.5 4A2.5 2.5 0 0112 6.5m0 0v13m0-13A2.5 2.5 0 0114.5 4a2.5 2.5 0 012.45 3A2.5 2.5 0 0119 9.5a2.5 2.5 0 01-1.5 2.29'),
                self::item('Research', 'research', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.247m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.247'),
                self::item('Devices', 'devices', 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM5 9h14M7 9V6a1 1 0 011-1h8a1 1 0 011 1v3m-1 0v4a1 1 0 01-1 1H9a1 1 0 01-1-1V9'),
            ],
        ];

        return $groups;
    }

    /**
     * Flat list of every destination (all groups concatenated). Handy for the
     * desktop sidebar where grouping isn't needed.
     *
     * @return list<array{label:string, path:string, icon:string}>
     */
    public static function flat(?Profile $profile = null): array
    {
        return array_merge(...array_values(self::groups($profile)));
    }

    /** @return array{label:string, path:string, icon:string} */
    private static function item(string $label, string $path, string $icon): array
    {
        return ['label' => $label, 'path' => $path, 'icon' => $icon];
    }
}
