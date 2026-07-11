{{--
    Sleep timeline v2 (web parity with the iOS `SleepTimeline`): a LAYERED chart over ONE clock axis
    (bedtime→wake, user tz), rendered as inline SVG. Three legible layers, top→bottom:

      1. HR-peaks overlay  — a thin HR trace + faint envelope fill, drawn ONLY across contiguous
         measured runs of `hr_series`; broken at gaps. Peaks (the "3am spike") read at a glance.
      2. Stage ribbon      — the v1 stepped four-lane hypnogram (Awake/REM/Light/Deep) + NODATA hatch.
         UNCHANGED — this stays the hero.
      3. Movement strip     — per-epoch restlessness teeth from `motion_series`: height/opacity ∝ motion,
         coloured by the epoch's stage, drawn only where measured. Calm deep → near-empty; restless
         light/wake → visible teeth.

    `hr_series` / `motion_series` are SPARSE `[{i,v}, …]` aligned to the SAME `epoch_sec` + 30s grid as
    `hypnogram` (epoch i's clock = epoch_sec + i×30). A missing epoch is a REAL gap — never interpolated,
    it renders as a hole, the same "show only what we measured" ethos as the NODATA hatch. Every colour
    comes from the ONE shared mapping (App\Support\SleepStages) — no second colour vocabulary here.

    Expects: $log (an App\Models\SleepLog). Older nights (no series / no stages) degrade honestly to the
    ribbon alone, or to a duration-only span bar when there's no per-stage data.
--}}
@php
    use App\Support\SleepStages;

    $hyp = is_array($log->hypnogram ?? null) ? $log->hypnogram : [];
    $n = count($hyp);
    $hasRibbon = $n > 4;

    // Night-start timestamp for the clock axis (mirrors App\Support\SleepDetail's epoch_sec logic).
    $start = $log->session_start
        ? $log->session_start->copy()
        : ($log->bedtime
            ? \Illuminate\Support\Carbon::parse($log->slept_at->toDateString().' '.$log->bedtime)
                ->when($log->wake_time && (string) $log->bedtime > (string) $log->wake_time, fn ($c) => $c->subDay())
            : $log->slept_at->copy()->startOfDay());

    $EPOCH = 30;                 // seconds per epoch
    $W = 1000; $H = 100;         // ribbon SVG user space; scaled to full width (preserveAspectRatio="none")
    $laneH = $H / SleepStages::LANES;
    $barH = $laneH * 0.62;

    // Collapse consecutive same-stage epochs into runs (awake→wake handled by the shared resolver).
    $runs = [];
    $i = 0;
    while ($i < $n) {
        $style = SleepStages::for((string) $hyp[$i]);
        $j = $i;
        while ($j < $n && SleepStages::for((string) $hyp[$j])['code'] === $style['code']) {
            $j++;
        }
        $runs[] = ['style' => $style, 'from' => $i, 'len' => $j - $i];
        $i = $j;
    }

    // Clock ticks across the axis (real times, user tz).
    $ticks = [];
    if ($n > 0) {
        for ($k = 0; $k <= 4; $k++) {
            $idx = (int) round(($n - 1) * $k / 4);
            $ticks[] = $start->copy()->addSeconds($idx * $EPOCH)->format('g:i A');
        }
    }

    // ── v2 overlays: sparse per-epoch HR + motion. The server (App\Support\SleepDetail / seal) exposes
    // these on the log; nights sealed before v2 simply lack them → null → we render the ribbon alone.
    // Normalise to [ epochIndex => value ], on-grid (0..n-1) and sorted, tolerating array OR JSON string.
    $decodeSeries = function ($raw) use ($n) {
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (! is_array($raw)) { return []; }
        $pts = [];
        foreach ($raw as $p) {
            if (! is_array($p) || ! array_key_exists('i', $p) || ! array_key_exists('v', $p)) { continue; }
            $idx = (int) $p['i'];
            if ($idx < 0 || $idx >= $n) { continue; }   // must land on the shared epoch grid
            $pts[$idx] = (float) $p['v'];                // dedupe by index
        }
        ksort($pts);
        return $pts;
    };

    $hr = $hasRibbon ? $decodeSeries($log->hr_series ?? null) : [];
    $motion = $hasRibbon ? $decodeSeries($log->motion_series ?? null) : [];
    $hasHr = count($hr) >= 2;
    $hasMotion = count($motion) >= 1;

    // HR: split into contiguous measured runs (a jump in epoch index = a gap → break the line).
    $hrH = 100; $hrPad = 16;
    $hrRuns = []; $hrMin = 0.0; $hrMax = 0.0; $hrSpan = 1.0;
    if ($hasHr) {
        $hrMin = min($hr); $hrMax = max($hr);
        $hrSpan = max(1.0, $hrMax - $hrMin);
        $run = []; $prev = null;
        foreach ($hr as $idx => $v) {
            if ($prev !== null && $idx !== $prev + 1) { $hrRuns[] = $run; $run = []; }
            $run[] = ['i' => $idx, 'v' => $v];
            $prev = $idx;
        }
        if ($run) { $hrRuns[] = $run; }
    }
    $hrY = fn ($v) => $hrH - $hrPad - (($v - $hrMin) / $hrSpan) * ($hrH - 2 * $hrPad);
    $hrHex = SleepStages::MAP['wake']['hex'];   // amber = the arousal channel; stays in the shared vocabulary

    // Movement: night-relative restless line (~70th percentile) so teeth read relative to THIS night.
    $mvH = 100; $mMax = 1e-6; $mThresh = INF;
    if ($hasMotion) {
        $mMax = max(max($motion), 1e-6);
        $sortedM = array_values($motion); sort($sortedM);
        $mThresh = $sortedM[(int) floor(0.7 * (count($sortedM) - 1))];
    }
@endphp

<div class="mt-5">
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Sleep timeline</div>

    @if ($hasRibbon)
        {{-- ─── Layer 1: HR-peaks overlay (measured runs only; holes where the band was off) ─── --}}
        @if ($hasHr)
            <div class="flex gap-2 items-stretch mb-1">
                <div class="flex w-10 shrink-0 flex-col justify-between py-0.5 text-right text-[9px] text-gray-500 nums" style="height: 44px;">
                    <span>{{ (int) round($hrMax) }}</span>
                    <span class="text-gray-600 uppercase tracking-wide">bpm</span>
                    <span>{{ (int) round($hrMin) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <svg viewBox="0 0 {{ $W }} {{ $hrH }}" preserveAspectRatio="none" class="w-full" style="height: 44px;" role="img" aria-label="Heart rate across the night — measured epochs only, gaps left as holes">
                        @foreach ($hrRuns as $run)
                            @php
                                $pts = [];
                                foreach ($run as $p) {
                                    $pts[] = round($p['i'] / $n * $W, 2).','.round($hrY($p['v']), 2);
                                }
                                $poly = implode(' ', $pts);
                                $x0 = round($run[0]['i'] / $n * $W, 2);
                                $xN = round($run[count($run) - 1]['i'] / $n * $W, 2);
                            @endphp
                            @if (count($run) === 1)
                                {{-- an isolated measured epoch: a dot, never a line across a gap --}}
                                <circle cx="{{ $x0 }}" cy="{{ round($hrY($run[0]['v']), 2) }}" r="2" fill="{{ $hrHex }}" opacity="0.85"></circle>
                            @else
                                <polygon points="{{ $x0 }},{{ $hrH }} {{ $poly }} {{ $xN }},{{ $hrH }}" fill="{{ $hrHex }}" opacity="0.10"></polygon>
                                <polyline points="{{ $poly }}" fill="none" stroke="{{ $hrHex }}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></polyline>
                            @endif
                        @endforeach
                    </svg>
                </div>
            </div>
        @endif

        {{-- ─── Layer 2: Stage ribbon (the hero — UNCHANGED from v1) ─── --}}
        <div class="flex gap-2">
            {{-- Lane labels, top→bottom --}}
            <div class="flex w-10 shrink-0 flex-col justify-between py-0.5 text-right text-[9px] text-gray-500" style="height: 128px;">
                @foreach (SleepStages::LANE_CODES as $code)
                    <span>{{ SleepStages::MAP[$code]['label'] }}</span>
                @endforeach
            </div>

            <div class="min-w-0 flex-1">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" class="w-full" style="height: 128px;" role="img" aria-label="Hypnogram of last night's sleep stages">
                    <defs>
                        {{-- NODATA coverage hole — a faint hatch, never a sleep color --}}
                        <pattern id="nodataHatch" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                            <rect width="8" height="8" fill="{{ SleepStages::MAP['nodata']['hex'] }}" opacity="0.22"></rect>
                            <line x1="0" y1="0" x2="0" y2="8" stroke="rgba(255,255,255,0.30)" stroke-width="1.4"></line>
                        </pattern>
                    </defs>
                    @foreach ($runs as $run)
                        @php
                            $s = $run['style'];
                            $x = $run['from'] / $n * $W;
                            $w = max(0.6, $run['len'] / $n * $W);
                        @endphp
                        @if ($s['hole'])
                            {{-- Full-height honest gap --}}
                            <rect x="{{ $x }}" y="0" width="{{ $w }}" height="{{ $H }}" fill="url(#nodataHatch)"></rect>
                        @else
                            @php $y = $s['lane'] * $laneH + ($laneH - $barH) / 2; @endphp
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $barH }}" rx="1.5" fill="{{ $s['hex'] }}"></rect>
                        @endif
                    @endforeach
                </svg>
            </div>
        </div>

        {{-- ─── Layer 3: Movement strip (restlessness teeth; only where measured) ─── --}}
        @if ($hasMotion)
            <div class="flex gap-2 items-center mt-1">
                <div class="w-10 shrink-0 text-right text-[9px] uppercase tracking-wide text-gray-600">Move</div>
                <div class="min-w-0 flex-1">
                    <svg viewBox="0 0 {{ $W }} {{ $mvH }}" preserveAspectRatio="none" class="w-full" style="height: 22px;" role="img" aria-label="Movement across the night — restlessness where measured, gaps left empty">
                        @foreach ($motion as $idx => $v)
                            @php
                                $x = $idx / $n * $W;
                                $bw = max(0.6, $W / $n);
                                $frac = min(1.0, $v / $mMax);
                                $bh = max(1.5, $frac * $mvH);
                                $restless = $v >= $mThresh;
                                $op = $restless ? min(0.95, 0.5 + 0.5 * $frac) : max(0.16, 0.5 * $frac);
                                $shex = SleepStages::for((string) ($hyp[$idx] ?? 'nodata'))['hex'];
                            @endphp
                            <rect x="{{ round($x, 2) }}" y="{{ round($mvH - $bh, 2) }}" width="{{ round($bw, 2) }}" height="{{ round($bh, 2) }}" fill="{{ $shex }}" opacity="{{ round($op, 2) }}"></rect>
                        @endforeach
                    </svg>
                </div>
            </div>
        @endif

        {{-- Shared clock-time axis (labels all three layers off the one epoch grid) --}}
        <div class="flex gap-2 mt-1.5">
            <div class="w-10 shrink-0"></div>
            <div class="min-w-0 flex-1">
                <div class="flex justify-between text-[10px] text-gray-600 nums">
                    @foreach ($ticks as $t)
                        <span>{{ $t }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        {{-- Duration-only fallback: honest span bar + low-signal note (matches the iOS state) --}}
        <div class="h-5 w-full overflow-hidden rounded-full bg-white/5">
            <div class="h-full rounded-full bg-titan-indigo/55" style="width: 100%"></div>
        </div>
        <p class="mt-2 text-xs text-gray-500">Stages unavailable — low signal this night.</p>
    @endif
</div>
