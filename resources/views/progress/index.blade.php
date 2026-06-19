<x-titan-layout title="Progress" subtitle="Your timeline & gallery">
    @php
        $vColor = ['ahead' => '#34d399', 'on_track' => '#34d399', 'steady' => '#fbbf24', 'behind' => '#fb7185', 'just_started' => '#9ca3af'];
        $pc = $physique ? ($vColor[$physique['verdict']] ?? '#818cf8') : '#818cf8';

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

        // Group photos by taken_at date for the timeline.
        $photoGroups = $photos->groupBy(fn ($p) => \Illuminate\Support\Carbon::parse($p->taken_at)->format('Y-m-d'));
    @endphp

    <div class="space-y-5 max-w-2xl">

        {{-- Progress photo gallery / timeline --}}
        <section class="rounded-2xl border border-white/5 bg-white/[0.03] p-5">
            <div class="flex items-center justify-between">
                <p class="font-display text-[0.7rem] font-bold uppercase tracking-[0.1em] text-gray-500">Progress photos</p>
            </div>

            @if ($photoGroups->isEmpty())
                <p class="mt-3 text-sm text-gray-500">No photos yet. Upload a progress photo from the coach — type <span class="text-gray-300">"log a progress photo"</span> to get started.</p>
            @else
                <div class="mt-4 space-y-6">
                    @foreach ($photoGroups as $date => $group)
                        <div>
                            <p class="mb-2 text-[0.65rem] font-semibold uppercase tracking-wider text-gray-500">
                                {{ \Illuminate\Support\Carbon::parse($date)->format('M j, Y') }}
                            </p>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach ($group as $photo)
                                    <div class="relative aspect-[3/4] overflow-hidden rounded-xl border border-white/10 bg-white/5">
                                        <img src="{{ $photo->photoUrl() }}" alt="{{ $photo->pose ?? 'progress' }} photo"
                                             loading="lazy" class="h-full w-full object-cover">
                                        @if ($photo->pose)
                                            <span class="absolute bottom-1.5 left-1.5 rounded-full bg-black/60 px-2 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-gray-300">{{ $photo->pose }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
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

    </div>
</x-titan-layout>
