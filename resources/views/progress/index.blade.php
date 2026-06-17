<x-titan-layout title="Progress" subtitle="Your trajectory to the dream physique">
    @php
        $vColor = ['ahead' => '#34d399', 'on_track' => '#34d399', 'steady' => '#fbbf24', 'behind' => '#fb7185', 'just_started' => '#9ca3af'];
        $pc = $physique ? ($vColor[$physique['verdict']] ?? '#818cf8') : '#818cf8';

        // Build an SVG polyline from a numeric series → points string within a 300x120 box.
        $spark = function (array $vals, float $min, float $max, float $w = 300, float $h = 110, float $pad = 8) {
            $n = count($vals);
            if ($n < 2) return '';
            $range = max(0.0001, $max - $min);
            return collect($vals)->map(function ($v, $i) use ($n, $min, $range, $w, $h, $pad) {
                $x = $pad + ($i * ($w - 2 * $pad)) / ($n - 1);
                $y = $h - $pad - (($v - $min) / $range) * ($h - 2 * $pad);
                return round($x, 1) . ',' . round($y, 1);
            })->implode(' ');
        };
    @endphp

    <div class="space-y-5 max-w-2xl">

        {{-- North star: dream physique --}}
        @if ($physique)
            <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
                <p class="font-display text-[0.7rem] font-bold uppercase tracking-[0.1em] text-gray-500">Dream physique</p>
                <div class="mt-3 flex gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-1.5">
                            <span class="font-display text-4xl font-black leading-none" style="color:{{ $pc }}">{{ $physique['step_pct'] }}%</span>
                            <span class="text-sm text-gray-500">to your goal</span>
                        </div>
                        <div class="mt-2.5 h-2 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full" style="width: {{ max(2, min(100, $physique['step_pct'])) }}%; background: {{ $pc }}"></div>
                        </div>
                        <span class="mt-2.5 inline-block rounded-full px-2.5 py-0.5 font-display text-xs font-bold" style="color:{{ $pc }}; background: {{ $pc }}22">{{ $physique['verdict_label'] }}</span>
                        @if (!is_null($physique['eta_weeks']))
                            <p class="mt-2 text-xs text-gray-400">~{{ $physique['eta_weeks'] }} week{{ $physique['eta_weeks'] === 1 ? '' : 's' }} to goal at this pace</p>
                        @endif
                        @if ($physique['description'])
                            <p class="mt-2 text-sm text-gray-300 leading-snug">{{ \Illuminate\Support\Str::limit($physique['description'], 120) }}</p>
                        @endif
                    </div>
                    @if ($physique['goal_image'])
                        <img src="{{ $physique['goal_image'] }}" alt="Dream physique" loading="lazy"
                             class="h-32 w-24 shrink-0 rounded-xl border border-white/10 object-cover">
                    @endif
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3 border-t border-white/5 pt-3">
                    <div><div class="text-[0.6rem] uppercase tracking-wide text-gray-500">Consistency</div><div class="font-display text-base font-bold text-gray-100">{{ is_null($physique['adherence_pct']) ? '—' : $physique['adherence_pct'].'%' }}</div></div>
                    <div><div class="text-[0.6rem] uppercase tracking-wide text-gray-500">Week score</div><div class="font-display text-base font-bold text-gray-100">{{ $physique['week_score'] ?? '—' }}</div></div>
                    <div><div class="text-[0.6rem] uppercase tracking-wide text-gray-500">Weight</div><div class="font-display text-base font-bold text-gray-100">{{ $physique['weight'] ? $physique['weight']['value'].' '.$physique['weight']['unit'] : '—' }}</div></div>
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-5 text-center">
                <p class="font-display text-lg font-bold text-gray-100">Set your dream physique</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-gray-400 leading-relaxed">This is Titan's north star. In the coach, upload a current photo and tell it your goal — it'll render your realistic future self and track every week against it.</p>
                <a href="/coach" class="mt-3 inline-block rounded-xl bg-indigo-500 px-4 py-2.5 text-sm font-bold text-white">Open the coach</a>
            </section>
        @endif

        {{-- Week-score trajectory --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
            <div class="flex items-baseline justify-between">
                <p class="font-display text-[0.7rem] font-bold uppercase tracking-[0.1em] text-gray-500">Week-score momentum</p>
                @if (count($weekScores) >= 2)<span class="text-xs text-gray-500">{{ count($weekScores) }} weeks</span>@endif
            </div>
            @if (count($weekScores) >= 2)
                <svg viewBox="0 0 300 120" class="mt-3 w-full" preserveAspectRatio="none" style="height:120px">
                    <line x1="8" y1="63" x2="292" y2="63" stroke="rgba(255,255,255,0.06)" stroke-width="1"/>
                    <polyline points="{{ $spark(array_column($weekScores, 'score'), 0, 100) }}" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                </svg>
                <div class="mt-1 flex justify-between text-[0.62rem] text-gray-600">
                    <span>{{ $weekScores[0]['label'] }}</span>
                    <span>{{ end($weekScores)['label'] }} · {{ end($weekScores)['score'] }}/100</span>
                </div>
            @else
                <p class="mt-3 text-sm text-gray-500">A couple of weeks in, your momentum trend shows up here.</p>
            @endif
        </section>

        {{-- Bodyweight trajectory --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
            <p class="font-display text-[0.7rem] font-bold uppercase tracking-[0.1em] text-gray-500">Bodyweight ({{ $weightUnit }})</p>
            @if (count($weightSeries) >= 2)
                @php $wv = array_column($weightSeries, 'value'); $wmin = min($wv); $wmax = max($wv); @endphp
                <svg viewBox="0 0 300 120" class="mt-3 w-full" preserveAspectRatio="none" style="height:120px">
                    <polyline points="{{ $spark($wv, $wmin - 0.5, $wmax + 0.5) }}" fill="none" stroke="#22d3ee" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                </svg>
                <div class="mt-1 flex justify-between text-[0.62rem] text-gray-600">
                    <span>{{ $weightSeries[0]['label'] }} · {{ $weightSeries[0]['value'] }}</span>
                    <span>{{ end($weightSeries)['label'] }} · {{ end($weightSeries)['value'] }} {{ $weightUnit }}</span>
                </div>
            @else
                <p class="mt-3 text-sm text-gray-500">Log your weight a few times and your trend line appears here.</p>
            @endif
        </section>
    </div>
</x-titan-layout>
