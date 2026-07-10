{{--
    Sleep timeline (web parity with the iOS `SleepTimeline`): a stepped four-lane hypnogram ribbon
    (Awake / REM / Light / Deep, top→bottom) across a real clock-time axis, rendered as inline SVG.
    NODATA epochs render as honest full-height hatched gaps — never painted as sleep. Every color/label
    comes from the ONE shared mapping (App\Support\SleepStages), mirroring TitanCore's SleepStage.

    Expects: $log (an App\Models\SleepLog). Falls back to an honest span bar + low-signal note when the
    night has no per-stage data (thin coverage), matching the iOS duration-only state.
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
    $W = 1000; $H = 100;         // SVG user space; scaled to full width (preserveAspectRatio="none")
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
@endphp

<div class="mt-5">
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mb-2">Sleep timeline</div>

    @if ($hasRibbon)
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

                {{-- Clock-time axis --}}
                <div class="mt-1.5 flex justify-between text-[10px] text-gray-600 nums">
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
