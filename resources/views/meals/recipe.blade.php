<x-titan-layout :title="$s->name" subtitle="A meal idea sized to your next meal">
    <div class="max-w-xl mx-auto space-y-5">

        <a href="{{ route('meals.index') }}" class="inline-flex items-center gap-1.5 text-sm text-gray-400 active:text-gray-200">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Meals
        </a>

        {{-- Hero photo --}}
        <div class="rounded-3xl border border-white/10 overflow-hidden bg-gray-950">
            <div class="relative aspect-[4/3]">
                @if ($s->imageUrl())
                    <img src="{{ $s->imageUrl() }}" alt="{{ $s->name }}" class="absolute inset-0 h-full w-full object-cover">
                @else
                    <div class="absolute inset-0 grid place-items-center text-5xl">🍲</div>
                @endif
            </div>
            <div class="p-4 md:p-5">
                <h1 class="font-display text-2xl font-bold text-gray-100">{{ $s->name }}</h1>
                @if ($s->description)<p class="text-sm text-gray-400 mt-1 leading-relaxed">{{ $s->description }}</p>@endif

                {{-- Macros --}}
                <div class="mt-4 grid grid-cols-4 gap-2 text-center">
                    @foreach ([['Cal', $s->calories ? number_format($s->calories) : '—', 'text-gray-100'], ['Protein', $s->protein_g ? (int) $s->protein_g.'g' : '—', 'text-emerald-300'], ['Carbs', $s->carbs_g ? (int) $s->carbs_g.'g' : '—', 'text-cyan-300'], ['Fat', $s->fat_g ? (int) $s->fat_g.'g' : '—', 'text-amber-300']] as [$lab, $val, $tone])
                        <div class="rounded-xl bg-white/[0.03] border border-white/5 py-2.5">
                            <div class="font-display text-lg font-bold nums {{ $tone }}">{{ $val }}</div>
                            <div class="text-[10px] uppercase tracking-wide text-gray-500">{{ $lab }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Ingredients --}}
        @if (! empty($s->ingredients))
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <h2 class="font-display font-bold text-gray-100 mb-3">Ingredients</h2>
                <ul class="space-y-2">
                    @foreach ($s->ingredients as $ing)
                        <li class="flex items-start gap-2.5 text-sm text-gray-300">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-400"></span>
                            <span>{{ $ing }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Extras to grab (not in your kitchen) --}}
        @if (! empty($s->extras))
            <div class="rounded-2xl border border-amber-500/20 bg-amber-500/[0.05] p-4 md:p-5">
                <h2 class="font-display font-bold text-amber-200 mb-2 flex items-center gap-2"><span>🛒</span> You'll need to grab</h2>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($s->extras as $x)
                        <span class="rounded-full border border-amber-500/25 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-100">{{ $x }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Steps --}}
        @if (! empty($s->steps))
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <h2 class="font-display font-bold text-gray-100 mb-3">How to make it</h2>
                <ol class="space-y-3">
                    @foreach ($s->steps as $i => $step)
                        <li class="flex gap-3 text-sm text-gray-200">
                            <span class="shrink-0 grid place-items-center h-6 w-6 rounded-full bg-indigo-500 text-[12px] font-bold text-white">{{ $i + 1 }}</span>
                            <span class="leading-relaxed pt-0.5">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        {{-- Log it --}}
        <form method="POST" action="{{ route('meals.suggestion.log', $s) }}">
            @csrf
            <button type="submit" class="w-full h-12 rounded-2xl bg-gradient-to-r from-indigo-500 to-cyan-500 text-sm font-semibold text-gray-950 active:opacity-90">
                I ate this — log it{{ $s->protein_g ? ' ('.(int) $s->protein_g.'g protein)' : '' }}
            </button>
        </form>
    </div>
</x-titan-layout>
