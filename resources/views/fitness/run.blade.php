<x-titan-layout :title="$session->title()" :subtitle="$session->started_at->format('l, M j · g:i A')">
    @php
        $imperial = ($units ?? 'metric') === 'imperial';
        $splitUnit = $imperial ? 'mi' : 'km';
        $splits = data_get($session->splits, $splitUnit, []);
        $profile_pts = $session->elevation_profile ?? [];
        $efforts = $session->best_efforts ?? [];

        // pace (s per unit) → "m:ss"
        $fmtPace = fn ($s) => $s && $s > 0 ? sprintf('%d:%02d', intdiv((int) round($s), 60), (int) round($s) % 60) : '—';
        // seconds → "h:mm:ss" / "m:ss"
        $fmtDur = function ($s) {
            $s = (int) round($s);
            $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
            return $h ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
        };
        $dist = $session->distance_km;
        $distNum = $dist ? number_format($imperial ? $dist * 0.621371 : $dist, 2) : '—';
        $distUnit = $imperial ? 'mi' : 'km';
        $distLabel = $dist ? "$distNum $distUnit" : '—';
        $movingLabel = $session->moving_time_s ? $fmtDur($session->moving_time_s) : ($session->duration_min ? $session->duration_min.' min' : '—');
        $avgPace = $session->formatPace($session->avg_pace_s_per_km, ! $imperial);
        $gapPace = $session->formatPace($session->gap_s_per_km, ! $imperial);
        $gain = $session->elevation_gain_m;
        $gainLabel = $gain !== null ? ($imperial ? round($gain * 3.28084).' ft' : $gain.' m') : null;

        // fastest split → longest bar (Strava style)
        $paces = collect($splits)->pluck('pace_s_per_unit')->filter(fn ($p) => $p > 0);
        $pMin = $paces->min() ?: 1; $pMax = $paces->max() ?: 1;
    @endphp

    <a href="{{ route('fitness.index') }}" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-gray-400 hover:text-gray-200">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        Fitness
    </a>

    {{-- ── Route map as a poster — hero distance + pace overlaid (the shareable artifact) ── --}}
    @php $mapUrl = $session->staticMapUrl(900, 600); @endphp
    @if ($mapUrl)
        <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-black">
            <img src="{{ $mapUrl }}" alt="Route map" class="aspect-[3/2] w-full object-cover" loading="lazy" />
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/85 via-black/10 to-black/20"></div>
            <div class="absolute right-4 top-3 text-[11px] font-semibold uppercase tracking-[0.2em] text-white/70">Titan</div>
            <div class="absolute inset-x-0 bottom-0 p-5">
                <div class="flex items-end gap-2">
                    <span class="font-display nums text-5xl font-black leading-none text-white sm:text-6xl">{{ $distNum }}</span>
                    <span class="mb-1 text-base font-semibold text-white/75">{{ $distUnit }}</span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px] font-semibold text-white/90 nums">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5 text-white/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>{{ $movingLabel }}</span>
                    @if ($avgPace)<span class="flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5 text-white/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>{{ $avgPace }}</span>@endif
                </div>
            </div>
        </div>
        <button type="button" onclick="shareRun()"
            class="mt-2 flex w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.04] py-3 text-[14px] font-semibold text-gray-200 hover:bg-white/[0.07] transition">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.7 10.7l6.6-3.4M8.7 13.3l6.6 3.4M18 8a3 3 0 100-6 3 3 0 000 6zM6 15a3 3 0 100-6 3 3 0 000 6zm12 7a3 3 0 100-6 3 3 0 000 6z"/></svg>
            Share this run
        </button>
    @else
        {{-- No route map (indoor/no-GPS run, or no Mapbox key) — still lead with the numbers. --}}
        <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
            @if ($dist)
                <div class="flex items-end gap-2">
                    <span class="font-display nums text-5xl font-black leading-none text-white sm:text-6xl">{{ $distNum }}</span>
                    <span class="mb-1 text-base font-semibold text-white/75">{{ $distUnit }}</span>
                </div>
            @else
                <div class="font-display nums text-5xl font-black leading-none text-white sm:text-6xl">{{ $movingLabel }}</div>
            @endif
            <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px] font-semibold text-gray-200 nums">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>{{ $movingLabel }}</span>
                @if ($avgPace)<span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>{{ $avgPace }}</span>@endif
            </div>
            @if ($session->distance_source === 'steps')
                <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-amber-400/10 px-2.5 py-1 text-[11px] font-medium text-amber-300/90">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Estimated from your steps — no GPS lock on this one
                </p>
            @elseif (! config('services.mapbox.token') && $session->hasRoute())
                <p class="mt-3 text-[12px] text-amber-300/70">Route tracked — add a <span class="font-mono">MAPBOX_API_TOKEN</span> to render the map.</p>
            @endif
        </div>
        @if ($dist)
            <button type="button" onclick="shareRun()"
                class="mt-2 flex w-full items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/[0.04] py-3 text-[14px] font-semibold text-gray-200 hover:bg-white/[0.07] transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8.7 10.7l6.6-3.4M8.7 13.3l6.6 3.4M18 8a3 3 0 100-6 3 3 0 000 6zM6 15a3 3 0 100-6 3 3 0 000 6zm12 7a3 3 0 100-6 3 3 0 000 6z"/></svg>
                Share this run
            </button>
        @endif
    @endif

    {{-- ── Secondary stats (calm — color reserved for GAP + effort) ────────── --}}
    <div class="mt-4 grid grid-cols-3 gap-2.5 sm:grid-cols-4">
        @if ($gapPace)<x-runstat label="GAP" :value="$gapPace" hint="Grade-adjusted — the pace it felt like on the flat" tone="text-emerald-300" />@endif
        @if ($gainLabel)<x-runstat label="Elev gain" :value="$gainLabel" />@endif
        @if ($session->avg_hr)<x-runstat label="Avg HR" :value="$session->avg_hr" unit="bpm" />@endif
        @if ($session->max_hr)<x-runstat label="Max HR" :value="$session->max_hr" unit="bpm" />@endif
        @if ($session->relative_effort)<x-runstat label="Effort" :value="$session->relative_effort" hint="Relative Effort — HR-zone-weighted load" tone="text-pink-300" />@endif
        @if ($session->calories_kcal)<x-runstat label="Calories" :value="$session->calories_kcal" unit="kcal" />@endif
        @if ($session->vo2max)<x-runstat label="VO₂max" :value="number_format($session->vo2max, 1)" tone="text-emerald-300" />@endif
    </div>

    {{-- ── Elevation profile ──────────────────────────────────────────────── --}}
    @if (count($profile_pts) >= 2)
        @php
            $alts = collect($profile_pts)->pluck('alt_m');
            $aMin = $alts->min(); $aMax = $alts->max(); $aRange = max(0.1, $aMax - $aMin);
            $dMax = collect($profile_pts)->max('d_km') ?: 1;
            $W = 320; $H = 60;
            $pts = collect($profile_pts)->map(function ($p) use ($dMax, $aMin, $aRange, $W, $H) {
                $x = ($p['d_km'] / $dMax) * $W;
                $y = $H - (($p['alt_m'] - $aMin) / $aRange) * ($H - 6) - 3;
                return round($x, 1).','.round($y, 1);
            })->implode(' ');
        @endphp
        <div class="mt-4 rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <div class="mb-2 flex items-center justify-between text-[11px] uppercase tracking-wider text-gray-500">
                <span>Elevation</span>
                <span class="text-gray-400 normal-case tracking-normal">{{ $gainLabel ? '↑ '.$gainLabel : '' }} @if ($session->elevation_loss_m !== null)<span class="text-gray-600">↓ {{ $imperial ? round($session->elevation_loss_m * 3.28084).' ft' : $session->elevation_loss_m.' m' }}</span>@endif</span>
            </div>
            <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" class="h-16 w-full">
                <polygon points="0,{{ $H }} {{ $pts }} {{ $W }},{{ $H }}" fill="#f59e0b" fill-opacity="0.12" />
                <polyline points="{{ $pts }}" fill="none" stroke="#f59e0b" stroke-width="1.5" stroke-opacity="0.85" vector-effect="non-scaling-stroke" />
            </svg>
        </div>
    @endif

    {{-- ── Splits ─────────────────────────────────────────────────────────── --}}
    @if (count($splits))
        <h2 class="mt-6 mb-2 text-[11px] uppercase tracking-wider text-gray-500">Splits · per {{ $splitUnit }}</h2>
        <div class="overflow-hidden rounded-2xl border border-white/5 bg-white/[0.03]">
            @foreach ($splits as $sp)
                @php
                    $p = $sp['pace_s_per_unit'] ?? 0;
                    // faster pace → longer bar; invert within [pMin,pMax]
                    $w = $pMax > $pMin ? 28 + 72 * (($pMax - $p) / ($pMax - $pMin)) : 100;
                    $partial = $sp['partial'] ?? false;
                @endphp
                <div class="flex items-center gap-3 px-4 py-2.5 @if (! $loop->last) border-b border-white/5 @endif">
                    <span class="w-6 shrink-0 text-[13px] tabular-nums {{ $partial ? 'text-gray-600' : 'text-gray-400' }}">{{ $sp['index'] }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="h-5 rounded-md bg-gradient-to-r from-emerald-500/70 to-emerald-400/40" style="width: {{ round($w) }}%"></div>
                    </div>
                    <span class="w-16 shrink-0 text-right text-[13px] font-semibold tabular-nums text-gray-100">{{ $fmtPace($p) }}<span class="text-[10px] font-normal text-gray-500">/{{ $splitUnit }}</span></span>
                    @if (($sp['elev_delta_m'] ?? 0) != 0)
                        <span class="w-12 shrink-0 text-right text-[11px] tabular-nums {{ $sp['elev_delta_m'] > 0 ? 'text-amber-400/70' : 'text-cyan-400/60' }}">{{ $sp['elev_delta_m'] > 0 ? '↑' : '↓' }}{{ abs(round($sp['elev_delta_m'])) }}m</span>
                    @else
                        <span class="w-12 shrink-0"></span>
                    @endif
                    <span class="w-12 shrink-0 text-right text-[11px] tabular-nums text-pink-400/70">@if (! empty($sp['avg_hr'])){{ $sp['avg_hr'] }}@endif</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Best efforts ───────────────────────────────────────────────────── --}}
    @if (count($efforts))
        <h2 class="mt-6 mb-2 text-[11px] uppercase tracking-wider text-gray-500">Best efforts</h2>
        <div class="flex flex-wrap gap-2">
            @foreach ($efforts as $label => $e)
                <div class="rounded-xl border border-white/5 bg-white/[0.03] px-3 py-2">
                    <div class="text-[11px] uppercase tracking-wide text-gray-500">{{ $label }}</div>
                    <div class="text-[15px] font-semibold tabular-nums text-gray-100">{{ $fmtDur($e['elapsed_s'] ?? 0) }}</div>
                    <div class="text-[10px] tabular-nums text-gray-500">{{ $session->formatPace((int) round($e['pace_s_per_km'] ?? 0), ! $imperial) }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <p class="mt-6 text-center text-[12px] text-gray-500">Nice work. <span class="text-gray-600">Sealed from your band — GPS-grade estimates.</span></p>

    <script>
        function shareRun() {
            const data = {
                title: 'Titan',
                text: @json("Ran {$distLabel}".($avgPace ? " at {$avgPace}" : '')." — tracked on Titan 🏃"),
                url: window.location.href,
            };
            if (navigator.share) { navigator.share(data).catch(() => {}); }
            else { navigator.clipboard?.writeText(data.text + ' ' + data.url); }
        }
    </script>
</x-titan-layout>
