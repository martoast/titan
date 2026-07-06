<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Single source of truth for navigation — shared by the chat drawer (components/chat-shell)
 * and the standard layout's sidebar + bottom bar (components/titan-layout).
 *
 * The IA MIRRORS the native iOS app exactly: five top-level tabs —
 *   Coach · Today · Trends · Community · You
 * Every destination folds under one of them (iOS surfaces sub-pages as cards inside a tab;
 * on web the tab's landing page does the same, and desktop shows the children in the sidebar
 * under the active tab). `cycle` is gated to cycle-tracking users.
 *
 * Icons are raw SVG `path` `d` strings (Heroicons-style), drawn at 24×24.
 */
class Nav
{
    /** The five tabs pinned to the mobile bottom bar, in order (label ⇄ iOS tab). */
    public const PRIMARY = ['coach', 'dashboard', 'progress', 'community', 'you'];

    /**
     * The five tabs, each with its landing `path`, `icon`, and fold-in `children`.
     *
     * @return list<array{label:string, path:string, icon:string, children:list<array{label:string,path:string,icon:string}>}>
     */
    public static function tabs(?Profile $profile = null): array
    {
        $showCycle = $profile ? Cycle::available($profile) : false;

        return [
            // 1 — Coach: the AI chat surface (its own full-height shell).
            self::tab('Coach', 'coach', 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 21l1.8-4A7.97 7.97 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z', []),

            // 2 — Today: the daily hub (rings) + every "how am I doing today" detail.
            self::tab('Today', 'dashboard', 'M4 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h5a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM14 14a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1h-4a1 1 0 01-1-1v-5z', array_values(array_filter([
                self::item('Recovery', 'recovery', 'M13 10V3L4 14h7v7l9-11h-7z'),
                self::item('Sleep', 'sleep', 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z'),
                self::item('Fitness', 'fitness', 'M3 12h3l2-7 4 14 2-7h7'),
                self::item('Workouts', 'workouts', 'M6.5 6.5l11 11M4 9l1.5-1.5M9 4L7.5 5.5m9 13L18 17m-1-9l2-2M2.5 11.5l3 3m13-3l-3-3'),
                self::item('Meals', 'meals', 'M5 3v7a3 3 0 006 0V3M8 3v18m9-18s2 1 2 5-2 4-2 4v7'),
                self::item('What you take', 'stack', 'M7 8h10a4 4 0 010 8H7a4 4 0 010-8zM12 8v8'),
                $showCycle ? self::item('Cycle', 'cycle', 'M21 12a9 9 0 11-2.64-6.36M21 4v4h-4') : null,
            ]))),

            // 3 — Trends: the longitudinal view (charts, labs, body, food history).
            self::tab('Trends', 'progress', 'M3 3v18h18M7 14l3-3 3 3 5-6', [
                self::item('Progress', 'progress', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'),
                self::item('Bloodwork', 'biomarkers', 'M12 3s5 5.5 5 9.5a5 5 0 11-10 0C7 8.5 12 3 12 3z'),
                self::item('Body', 'body', 'M12 4a2 2 0 100-4 2 2 0 000 4zM6 8h12M9 8v12M15 8v12M9 12h6'),
                self::item('Foods', 'foods', 'M4 6h16M4 10h16M4 14h10M4 18h10'),
            ]),

            // 4 — Community: the social surface (feed, leaderboards, athletes).
            self::tab('Community', 'community', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 00-3-3M5 12a3 3 0 013-3', []),

            // 5 — You: profile, band, and the deeper library/setup.
            self::tab('You', 'you', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', [
                self::item('Your band', 'devices', 'M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM5 9h14M7 9V6a1 1 0 011-1h8a1 1 0 011 1v3m-1 0v4a1 1 0 01-1 1H9a1 1 0 01-1-1V9'),
                self::item('Physique', 'photos', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'),
                self::item('The Brain', 'brain', 'M9.5 4a2.5 2.5 0 00-2.45 3A2.5 2.5 0 005 9.5a2.5 2.5 0 001.5 2.29M9.5 4A2.5 2.5 0 0112 6.5m0 0v13m0-13A2.5 2.5 0 0114.5 4a2.5 2.5 0 012.45 3A2.5 2.5 0 0119 9.5a2.5 2.5 0 01-1.5 2.29'),
                self::item('Research', 'research', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.247m0-13C13.168 5.477 14.754 5 16.5 5s3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18s-3.332.477-4.5 1.247'),
                self::item('Notifications', 'notifications', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'),
                self::item('Connect', 'connect', 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 10-5.656-5.656l-1.1 1.1'),
                self::item('Account', 'profile', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'),
            ]),
        ];
    }

    /**
     * The tab whose landing path or one of whose children matches the current path — for
     * active-state highlighting. Returns the tab's `path` (e.g. 'dashboard') or null.
     */
    public static function activeTab(string $currentPath, ?Profile $profile = null): ?string
    {
        $currentPath = trim($currentPath, '/');
        $seg = explode('/', $currentPath)[0] ?: 'dashboard';
        foreach (self::tabs($profile) as $tab) {
            if ($tab['path'] === $seg) {
                return $tab['path'];
            }
            foreach ($tab['children'] as $child) {
                if ($child['path'] === $seg) {
                    return $tab['path'];
                }
            }
        }

        return null;
    }

    /**
     * Grouped navigation (label => items) kept for the chat drawer / "More" surfaces —
     * now derived from the 5 tabs (tab label => its children, tabs with no children list
     * themselves). Preserves the old Nav::groups() contract.
     *
     * @return array<string, list<array{label:string, path:string, icon:string}>>
     */
    public static function groups(?Profile $profile = null): array
    {
        $groups = [];
        foreach (self::tabs($profile) as $tab) {
            $self = self::item($tab['label'], $tab['path'], $tab['icon']);
            $groups[$tab['label']] = $tab['children'] ? array_merge([$self], $tab['children']) : [$self];
        }

        return $groups;
    }

    /**
     * Flat list of every destination (all tabs + children concatenated).
     *
     * @return list<array{label:string, path:string, icon:string}>
     */
    public static function flat(?Profile $profile = null): array
    {
        $out = [];
        foreach (self::tabs($profile) as $tab) {
            $out[] = self::item($tab['label'], $tab['path'], $tab['icon']);
            foreach ($tab['children'] as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** @return array{label:string, path:string, icon:string, children:list<array{label:string,path:string,icon:string}>} */
    private static function tab(string $label, string $path, string $icon, array $children): array
    {
        return ['label' => $label, 'path' => $path, 'icon' => $icon, 'children' => $children];
    }

    /** @return array{label:string, path:string, icon:string} */
    private static function item(string $label, string $path, string $icon): array
    {
        return ['label' => $label, 'path' => $path, 'icon' => $icon];
    }
}
