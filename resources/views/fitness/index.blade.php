<x-titan-layout title="Fitness" subtitle="VO₂max, cardio sessions & heart-rate recovery">
    @php
        $vo2 = $latestVo2?->vo2max;
        $vo2Tone = match (true) {
            $vo2 === null => 'text-gray-400',
            $vo2 >= 52 => 'text-emerald-300',
            $vo2 >= 44 => 'text-cyan-300',
            $vo2 >= 36 => 'text-amber-300',
            default => 'text-orange-300',
        };
        $levelLabel = $latestVo2?->fitness_level ? ucfirst($latestVo2->fitness_level) : null;
        $icon = fn ($t) => match ($t) {
            'run' => 'M13 4a1 1 0 100 2 1 1 0 000-2zM7 21l3-6 3 2 2 4M9 11l3-2 3 1 2-2',
            'cycle' => 'M5 18a3 3 0 100-6 3 3 0 000 6zm14 0a3 3 0 100-6 3 3 0 000 6zM9 15l3-7h3l-2 7M12 8l-1-3h3',
            'walk' => 'M13 4a1 1 0 100 2 1 1 0 000-2zM8 21l2-5 2 1 1 4M10 12l2-3 3 2',
            default => 'M3 12h3l2-7 4 14 2-7h7',
        };
    @endphp

    {{-- VO2max hero --}}
    <div class="rounded-3xl border border-white/5 bg-gradient-to-b from-white/[0.05] to-white/[0.02] p-6 text-center">
        <p class="text-[11px] uppercase tracking-wider text-gray-500">Estimated VO₂max</p>
        <div class="mt-1 flex items-end justify-center gap-2">
            <span class="font-display text-6xl font-black nums {{ $vo2Tone }} leading-none">{{ $vo2 !== null ? number_format($vo2, 1) : '—' }}</span>
            <span class="mb-1 text-sm text-gray-500">ml/kg/min</span>
        </div>
        @if ($vo2 !== null)
            <p class="mt-2 text-sm {{ $vo2Tone }} font-semibold">{{ $levelLabel }} <span class="text-gray-500 font-normal">· ± {{ $latestVo2->plusminus ?? '5.6' }} — track the trend, not one reading</span></p>

            {{-- Lightweight VO2max trend sparkline --}}
            @if ($vo2Trend->count() >= 2)
                @php
                    $vals = $vo2Trend->pluck('vo2max');
                    $min = $vals->min() - 1; $max = $vals->max() + 1; $span = max($max - $min, 0.1);
                    $w = 280; $h = 48; $n = $vals->count();
                    $pts = $vo2Trend->values()->map(function ($p, $i) use ($w, $h, $n, $min, $span) {
                        $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
                        $y = $h - (($p['vo2max'] - $min) / $span) * $h;
                        return round($x, 1).','.round($y, 1);
                    })->implode(' ');
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mx-auto mt-4 w-full max-w-xs" preserveAspectRatio="none">
                    <polyline points="{{ $pts }}" fill="none" stroke="currentColor" stroke-width="2" class="{{ $vo2Tone }}" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <p class="text-[10px] uppercase tracking-wider text-gray-600">VO₂max over your last {{ $vo2Trend->count() }} runs</p>
            @endif
        @else
            <p class="mt-2 text-sm text-gray-400">Log a GPS-paced run with the band to calibrate your VO₂max.</p>
        @endif
    </div>

    {{-- This-week load --}}
    <div class="mt-3 grid grid-cols-2 gap-3">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">This week · load</div>
            <div class="font-display text-3xl font-bold nums text-indigo-300 mt-1 leading-none">{{ $weekTrimp > 0 ? number_format($weekTrimp, 0) : '—' }}<span class="text-gray-500 text-base font-normal"> TRIMP</span></div>
        </div>
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            <div class="text-[11px] uppercase tracking-wide text-gray-500">Latest recovery</div>
            <div class="font-display text-3xl font-bold nums text-cyan-300 mt-1 leading-none">{{ $latestHrr !== null ? number_format($latestHrr, 0) : '—' }}<span class="text-gray-500 text-base font-normal"> HRR</span></div>
        </div>
    </div>

    {{-- Sessions --}}
    <h2 class="mt-6 mb-2 text-[11px] uppercase tracking-wider text-gray-500">Recent sessions</h2>
    @if ($sessions->isEmpty())
        <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] p-8 text-center text-sm text-gray-400">
            No cardio sessions yet. Start a workout on your band — runs, rides and walks land here automatically.
        </div>
    @else
        <div class="space-y-2">
            @foreach ($sessions as $s)
                <div class="flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[0.03] p-3.5">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/5 text-indigo-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon($s->activity_type) }}" /></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="font-semibold text-gray-100 truncate">{{ $s->title() }}</span>
                            <span class="text-[11px] text-gray-500 shrink-0">{{ $s->started_at->diffForHumans() }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[12px] text-gray-400 nums">
                            @if ($s->duration_min)<span>{{ $s->duration_min }} min</span>@endif
                            @if ($s->distance_km)<span>{{ number_format($s->distance_km, 1) }} km</span>@endif
                            @if ($s->avg_hr)<span>{{ $s->avg_hr }}<span class="text-gray-600">/{{ $s->max_hr }}</span> bpm</span>@endif
                            @if ($s->trimp)<span>TRIMP {{ number_format($s->trimp, 0) }}</span>@endif
                            @if ($s->calories_kcal)<span>{{ $s->calories_kcal }} kcal</span>@endif
                            @if ($s->hrr_bpm)<span class="text-cyan-400/80">HRR {{ number_format($s->hrr_bpm, 0) }}</span>@endif
                            @if ($s->vo2max)<span class="text-emerald-400/80">VO₂ {{ number_format($s->vo2max, 1) }}</span>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-titan-layout>
