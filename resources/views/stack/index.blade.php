<x-titan-layout title="What you take" subtitle="Supplements and medications, cross-checked.">
    @php
        // Time-of-day glyphs, shared by the (Alpine-rendered) checklist and the static protocol list.
        $slotIcons = ['morning' => '☀', 'midday' => '🌤', 'evening' => '☾', 'night' => '🌙', 'anytime' => '•'];
        $freqLabels = ['daily' => 'daily', 'specific_days' => 'on set days', 'as_needed' => 'as needed'];

        $schedLabel = function ($item) use ($slotIcons, $freqLabels) {
            $times = collect($item->slots())->map(fn ($s) => $slotIcons[$s] ?? '•')->implode(' ');
            $freq = $item->schedule['frequency'] ?? 'daily';
            return trim($times.' · '.($freqLabels[$freq] ?? $freq));
        };

        // Severity tiers — informational, never alarming red. Major is a warm amber, not crisis red.
        $sevStyles = [
            'major'    => ['label' => 'Major',        'dot' => 'bg-orange-400',   'pill' => 'border-orange-500/25 bg-orange-500/10 text-orange-200'],
            'moderate' => ['label' => 'Moderate',     'dot' => 'bg-titan-amber',  'pill' => 'border-titan-amber/25 bg-titan-amber/10 text-titan-amber'],
            'timing'   => ['label' => 'Timing',       'dot' => 'bg-titan-cyan',   'pill' => 'border-titan-cyan/25 bg-titan-cyan/10 text-titan-cyan'],
            'info'     => ['label' => 'Good to know', 'dot' => 'bg-titan-indigo', 'pill' => 'border-titan-indigo/25 bg-titan-indigo/10 text-titan-indigo'],
        ];

        $groups = ['Supplements' => $supplements, 'Medications' => $medications, 'Other' => $others];
        $hasItems = $supplements->isNotEmpty() || $medications->isNotEmpty() || $others->isNotEmpty();

        // Raw, trimmed dose number for prefilling edit inputs ("5000.00" → "5000").
        $rawDose = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    @endphp

    <div x-data="stackPage(@js($today))" x-init="init()">

        {{-- ============================================================= --}}
        {{-- Hero — today's checklist, grouped by time of day               --}}
        {{-- ============================================================= --}}
        <x-card pad="p-4 md:p-5" class="mb-6">
            <div class="flex items-center justify-between mb-4">
                <div class="min-w-0">
                    <div class="font-display text-base font-bold text-gray-100">Today</div>
                    <div class="text-[11px] text-gray-500">One tap to log a dose</div>
                </div>
                <button type="button" @click="openAdd()" aria-label="Add something you take"
                        class="h-10 w-10 shrink-0 grid place-items-center rounded-chip bg-titan-mint text-titan-bg active:bg-titan-mint/80">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                </button>
            </div>

            {{-- Empty (nothing scheduled today) --}}
            <template x-if="today.slots.length === 0">
                <div class="text-center py-6">
                    <p class="text-sm text-gray-400">Nothing here yet. Add a supplement or medication — search it, or just snap the bottle.</p>
                </div>
            </template>

            {{-- Slots --}}
            <div class="space-y-3">
                <template x-for="s in today.slots" :key="s.key">
                    <div x-show="slotVisible(s)" x-cloak class="rounded-chip border border-white/5 bg-black/20 overflow-hidden">
                        <button type="button" @click="toggle(s)" class="w-full flex items-center justify-between gap-3 px-3.5 py-2.5">
                            <span class="flex items-center gap-2 min-w-0">
                                <span class="text-base leading-none" x-text="slotIcon(s.key)"></span>
                                <span class="font-display font-bold text-gray-200" x-text="s.label"></span>
                            </span>
                            <span class="flex items-center gap-2 shrink-0">
                                <span x-show="untaken(s) === 0" x-cloak class="text-[11px] text-titan-mint"
                                      x-text="s.label + ' — done ' + slotIcon(s.key)"></span>
                                <svg class="h-4 w-4 text-gray-600 transition" :class="isOpen(s) && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </button>

                        <div x-show="isOpen(s)" x-collapse>
                            <div class="px-2 pb-2 space-y-0.5">
                                <template x-for="item in s.items" :key="item.id + ':' + item.slot">
                                    <button type="button" @click="item.taken ? undo(item) : take(item)" :disabled="busy"
                                            class="w-full flex items-center gap-3 rounded-lg px-2.5 py-2.5 text-left transition active:bg-white/5"
                                            :class="item.taken && 'opacity-50'">
                                        <span class="grid place-items-center h-6 w-6 shrink-0 rounded-full border transition"
                                              :class="item.taken ? 'border-titan-mint/60 bg-titan-mint/20 text-titan-mint' : 'border-white/20 text-transparent'">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                        <span class="flex-1 min-w-0">
                                            <span class="flex items-center gap-1.5">
                                                <span class="font-medium text-gray-100 truncate" x-text="item.name"></span>
                                                <span x-show="item.kind === 'medication'" class="text-[11px] text-gray-500" title="medication">℞</span>
                                            </span>
                                            <span class="block text-xs text-gray-500" x-text="item.dose + (item.with_food ? ' · with food' : '')"></span>
                                        </span>
                                        <span class="shrink-0 text-[11px] font-semibold"
                                              :class="item.taken ? 'text-gray-600' : 'text-titan-mint'"
                                              x-text="item.taken ? 'undo' : 'take'"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Calm footer + worth-knowing whisper --}}
            <div x-show="today.slots.length > 0" class="mt-4 flex items-center justify-between">
                <p class="text-sm text-gray-400" x-text="today.footer"></p>
                <span x-show="today.counts.extra > 0" x-cloak class="text-[11px] text-gray-600" x-text="'+' + today.counts.extra + ' extra'"></span>
            </div>

            <a x-show="today.worth_knowing > 0" x-cloak href="#worth-knowing"
               class="mt-3 inline-flex items-center gap-2 rounded-chip border border-titan-indigo/20 bg-titan-indigo/[0.08] px-3 py-2 text-xs text-titan-indigo active:bg-titan-indigo/15">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span x-text="today.worth_knowing + (today.worth_knowing === 1 ? ' thing worth knowing' : ' things worth knowing')"></span>
                <span aria-hidden="true">→</span>
            </a>
        </x-card>

        {{-- ============================================================= --}}
        {{-- Full protocol                                                  --}}
        {{-- ============================================================= --}}
        @if ($hasItems)
            <div class="space-y-6 mb-6">
                @foreach ($groups as $groupLabel => $items)
                    @continue($items->isEmpty())
                    <div>
                        <x-section-header :title="$groupLabel" :trailing="(string) $items->count()" />
                        <div class="space-y-2">
                            @foreach ($items as $item)
                                @php $pct = $adherence[$item->id] ?? null; @endphp
                                <x-card pad="p-0" class="overflow-hidden"
                                     x-data="{ open: false, edit: false }">
                                    {{-- Row summary --}}
                                    <button type="button" @click="open = !open" class="w-full flex items-center gap-3 p-4 text-left">
                                        @if ($item->photoUrl())
                                            <img src="{{ $item->photoUrl() }}" alt="" class="h-11 w-11 rounded-lg object-cover shrink-0 bg-gray-950">
                                        @else
                                            <div class="h-11 w-11 rounded-lg bg-gray-950 grid place-items-center shrink-0">
                                                <svg class="h-5 w-5 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10a4 4 0 010 8H7a4 4 0 010-8zM12 8v8"/></svg>
                                            </div>
                                        @endif
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <span class="font-semibold text-gray-100 truncate">{{ $item->name }}</span>
                                                @if ($item->kind === 'medication')<span class="text-[11px] text-gray-500" title="medication">℞</span>@endif
                                                @unless ($item->active)<span class="rounded-full bg-white/5 px-1.5 py-0.5 text-[10px] text-gray-500">paused</span>@endunless
                                            </div>
                                            <div class="text-xs text-gray-500 mt-0.5 truncate">
                                                {{ $item->doseLabel() ?: '—' }} · {{ $schedLabel($item) }}
                                            </div>
                                        </div>
                                        @if ($pct !== null)
                                            <div class="shrink-0 text-right">
                                                <div class="text-sm font-semibold nums {{ $pct >= 85 ? 'text-titan-mint' : ($pct >= 60 ? 'text-titan-amber' : 'text-gray-400') }}">{{ $pct }}%</div>
                                                <div class="text-[10px] text-gray-600">14-day</div>
                                            </div>
                                        @endif
                                        <svg class="h-4 w-4 text-gray-600 shrink-0 transition" :class="open && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </button>

                                    {{-- Row detail --}}
                                    <div x-show="open" x-collapse>
                                        <div class="px-4 pb-4 border-t border-white/5 pt-3">
                                            @if ($pct !== null)
                                                <div class="mb-3">
                                                    <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1">
                                                        <span>Adherence (last 14 days)</span><span class="nums">{{ $pct }}%</span>
                                                    </div>
                                                    <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
                                                        <div class="h-full rounded-full {{ $pct >= 85 ? 'bg-titan-mint/70' : ($pct >= 60 ? 'bg-titan-amber/70' : 'bg-gray-500/60') }}" style="width: {{ $pct }}%"></div>
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($item->brand || $item->notes)
                                                <div class="text-xs text-gray-400 space-y-1 mb-3">
                                                    @if ($item->brand)<div><span class="text-gray-600">Brand</span> · {{ $item->brand }}</div>@endif
                                                    @if ($item->notes)<div class="leading-relaxed">{{ $item->notes }}</div>@endif
                                                </div>
                                            @endif

                                            {{-- Actions --}}
                                            <div x-show="!edit" class="flex items-center gap-2">
                                                <button type="button" @click="edit = true"
                                                        class="rounded-lg bg-white/5 border border-white/10 px-3 py-1.5 text-xs font-medium text-gray-200 active:bg-white/10">Edit</button>
                                                <form method="POST" action="{{ route('stack.update', $item->id) }}">
                                                    @csrf @method('PATCH')
                                                    <input type="hidden" name="active" value="{{ $item->active ? 0 : 1 }}">
                                                    <button type="submit" class="rounded-lg bg-white/5 border border-white/10 px-3 py-1.5 text-xs font-medium text-gray-300 active:bg-white/10">{{ $item->active ? 'Pause' : 'Resume' }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('stack.destroy', $item->id) }}" onsubmit="return confirm('Remove {{ addslashes($item->name) }} from your list?')" class="ml-auto">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="text-gray-600 hover:text-titan-pink px-2 py-1.5" title="Remove">
                                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                    </button>
                                                </form>
                                            </div>

                                            {{-- Edit form (fallback full editor) --}}
                                            <form x-show="edit" x-cloak method="POST" action="{{ route('stack.update', $item->id) }}" class="space-y-3 mt-1">
                                                @csrf @method('PATCH')
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="col-span-2 block">
                                                        <span class="text-[11px] text-gray-500">Name</span>
                                                        <input type="text" name="name" value="{{ $item->name }}" required
                                                               class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-[11px] text-gray-500">Dose</span>
                                                        <input type="number" step="any" min="0" name="dose_amount" value="{{ $rawDose($item->dose_amount) }}"
                                                               class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                                    </label>
                                                    <label class="block">
                                                        <span class="text-[11px] text-gray-500">Unit</span>
                                                        <input type="text" name="dose_unit" value="{{ $item->dose_unit }}" placeholder="mg, IU, caps"
                                                               class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                                    </label>
                                                    <label class="col-span-2 block">
                                                        <span class="text-[11px] text-gray-500">Form</span>
                                                        <input type="text" name="form" value="{{ $item->form }}" placeholder="softgel, tablet, powder"
                                                               class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                                    </label>
                                                </div>

                                                <div>
                                                    <span class="text-[11px] text-gray-500">When</span>
                                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                                        @foreach (['morning' => '☀ Morning', 'midday' => '🌤 Midday', 'evening' => '☾ Evening', 'night' => '🌙 Night', 'anytime' => '• Anytime'] as $slot => $lbl)
                                                            <label class="cursor-pointer">
                                                                <input type="checkbox" name="schedule[times][]" value="{{ $slot }}" class="peer sr-only" @checked(in_array($slot, $item->slots()))>
                                                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $lbl }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                                    <div>
                                                        <span class="text-[11px] text-gray-500">Repeat</span>
                                                        <div class="mt-1 flex gap-1.5">
                                                            @php $curFreq = $item->schedule['frequency'] ?? 'daily'; @endphp
                                                            @foreach (['daily' => 'Daily', 'specific_days' => 'Set days', 'as_needed' => 'As needed'] as $fk => $flbl)
                                                                <label class="cursor-pointer">
                                                                    <input type="radio" name="schedule[frequency]" value="{{ $fk }}" class="peer sr-only" @checked($curFreq === $fk)>
                                                                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $flbl }}</span>
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                    <label class="cursor-pointer mt-4">
                                                        <input type="checkbox" name="schedule[with_food]" value="1" class="peer sr-only" @checked($item->schedule['with_food'] ?? false)>
                                                        <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">🍽 With food</span>
                                                    </label>
                                                </div>

                                                <div>
                                                    <span class="text-[11px] text-gray-500">Type</span>
                                                    <div class="mt-1 flex gap-1.5">
                                                        @foreach (['supplement' => 'Supplement', 'medication' => 'Medication', 'other' => 'Other'] as $kk => $klbl)
                                                            <label class="cursor-pointer">
                                                                <input type="radio" name="kind" value="{{ $kk }}" class="peer sr-only" @checked($item->kind === $kk)>
                                                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $klbl }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                <label class="block">
                                                    <span class="text-[11px] text-gray-500">Notes</span>
                                                    <textarea name="notes" rows="2" class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">{{ $item->notes }}</textarea>
                                                </label>

                                                <div class="flex items-center gap-2">
                                                    <button type="submit" class="rounded-chip bg-titan-mint hover:bg-titan-mint/90 px-4 py-2 text-sm font-medium text-titan-bg">Save changes</button>
                                                    <button type="button" @click="edit = false" class="text-xs text-gray-500 active:text-gray-300">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </x-card>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-card border border-dashed border-white/10 bg-white/[0.02] p-8 text-center mb-6">
                <p class="text-gray-400 text-sm">Nothing here yet. Add a supplement or medication — search it, or just snap the bottle.</p>
                <button type="button" @click="openAdd()" class="mt-3 inline-flex items-center gap-1.5 rounded-chip bg-titan-mint px-4 py-2 text-sm font-semibold text-titan-bg active:bg-titan-mint/80">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add your first
                </button>
            </div>
        @endif

        {{-- ============================================================= --}}
        {{-- Worth knowing — interactions (informational, cited, calm)      --}}
        {{-- ============================================================= --}}
        <x-card id="worth-knowing" pad="p-4 md:p-5" class="mb-6 scroll-mt-20">
            <div class="flex items-center gap-2 mb-3">
                <svg class="h-5 w-5 text-titan-indigo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <h2 class="font-display text-base font-bold text-gray-100">Worth knowing</h2>
            </div>

            {{-- Pinned disclaimer --}}
            <div class="rounded-chip border border-titan-indigo/20 bg-titan-indigo/[0.07] px-3.5 py-2.5 text-xs text-titan-indigo/90 leading-relaxed mb-4">
                {{ $disclaimer }}
            </div>

            @if ($flags->isEmpty())
                <p class="text-sm text-gray-500">Nothing flagged across what you take right now. As your list grows, anything reported in the literature or on a label will show up here.</p>
            @else
                <div class="space-y-2.5">
                    @foreach ($flags as $flag)
                        @php $sv = $sevStyles[$flag->severity] ?? $sevStyles['info']; @endphp
                        <div class="rounded-chip border border-white/5 bg-black/20 p-3.5">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $sv['dot'] }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-semibold text-gray-100 text-sm">{{ $flag->a_name }} + {{ $flag->b_name }}</span>
                                        <span class="rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $sv['pill'] }}">{{ $sv['label'] }}</span>
                                    </div>
                                    <p class="text-sm text-gray-400 mt-1 leading-relaxed">{{ $flag->summary }}</p>
                                    @if ($flag->source)
                                        <p class="text-[11px] text-gray-600 mt-1.5">Source: {{ $flag->source }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        {{-- ============================================================= --}}
        {{-- Add sheet (bottom sheet) — two first-class paths               --}}
        {{-- ============================================================= --}}
        <div x-cloak>
            {{-- backdrop --}}
            <div x-show="addOpen" x-transition.opacity @click="closeAdd()" class="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm"></div>

            <div x-show="addOpen"
                 x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 class="fixed inset-x-0 bottom-0 z-50 max-h-[90dvh] flex flex-col rounded-t-3xl border-t border-white/10 bg-titan-bg2 pb-safe md:inset-x-auto md:left-1/2 md:bottom-auto md:top-1/2 md:-translate-x-1/2 md:-translate-y-1/2 md:w-[34rem] md:max-w-[calc(100vw-2rem)] md:rounded-3xl md:border">
                <div class="flex justify-center pt-3 md:hidden"><div class="h-1.5 w-10 rounded-full bg-white/20"></div></div>

                {{-- header --}}
                <div class="flex items-center gap-3 px-5 pt-3 pb-2 border-b border-white/5">
                    <button type="button" x-show="addView !== 'home'" @click="back()" class="text-gray-500 -ml-1 p-1.5" aria-label="Back">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span class="font-display text-lg font-bold text-gray-100"
                          x-text="addView === 'home' ? 'Add to what you take' : (addView === 'shelf' ? 'Add my whole shelf' : (addView === 'confirm' ? 'Confirm the details' : 'Add one'))"></span>
                    <button type="button" @click="closeAdd()" class="ml-auto text-gray-500 p-1.5" aria-label="Close">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="overflow-y-auto px-5 py-4">

                    {{-- ---- HOME: the two paths ---- --}}
                    <div x-show="addView === 'home'" class="space-y-3">
                        <button type="button" @click="addView = 'one'"
                                class="w-full flex items-center gap-3 rounded-card border border-white/10 bg-white/[0.03] p-4 text-left active:bg-white/[0.06]">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-chip bg-titan-mint/15 text-titan-mint">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-display font-bold text-gray-100">Add one</span>
                                <span class="block text-xs text-gray-500">Search by name, or snap a single bottle</span>
                            </span>
                        </button>

                        <button type="button" @click="addView = 'shelf'"
                                class="w-full flex items-center gap-3 rounded-card border border-white/10 bg-white/[0.03] p-4 text-left active:bg-white/[0.06]">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-chip bg-titan-cyan/15 text-titan-cyan">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-display font-bold text-gray-100">Add my whole shelf</span>
                                <span class="block text-xs text-gray-500">One photo of all the bottles — confirm each</span>
                            </span>
                        </button>

                        <p class="text-[11px] text-gray-600 text-center pt-1">Tip: you can also just tell your coach “add creatine 5g every morning.”</p>
                    </div>

                    {{-- ---- ADD ONE: search + snap a bottle ---- --}}
                    <div x-show="addView === 'one'" class="space-y-4">
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                            <input type="text" x-model="query" @input.debounce.300ms="search()" placeholder="e.g. vitamin d3, lisinopril…"
                                   class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 pl-9 pr-3 text-sm text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                        </div>

                        {{-- results --}}
                        <div x-show="searching" class="text-xs text-gray-500 px-1">Searching…</div>
                        <div x-show="!searching && results.length > 0" class="space-y-1.5">
                            <template x-for="(r, i) in results" :key="i">
                                <button type="button" @click="pick(r)" class="w-full flex items-center justify-between gap-3 rounded-chip border border-white/5 bg-black/20 px-3.5 py-2.5 text-left active:bg-white/5">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-gray-100 truncate" x-text="r.name + (r.dose_amount ? ' · ' + (r.dose_amount + ' ' + (r.dose_unit || '')).trim() : '')"></span>
                                        <span class="block text-[11px] text-gray-500 truncate" x-text="[r.brand, r.kind, r.source].filter(Boolean).join(' · ')"></span>
                                    </span>
                                    <span class="text-[11px] font-semibold text-titan-mint shrink-0">Add</span>
                                </button>
                            </template>
                        </div>
                        <p x-show="!searching && query.trim().length >= 2 && results.length === 0" class="text-xs text-gray-500 px-1">
                            No matches.
                            <button type="button" @click="pickManual()" class="text-titan-mint font-medium">Add “<span x-text="query"></span>” manually →</button>
                        </p>

                        {{-- snap a bottle --}}
                        <div class="pt-1">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="h-px flex-1 bg-white/5"></div><span class="text-[11px] text-gray-600">or</span><div class="h-px flex-1 bg-white/5"></div>
                            </div>
                            <label class="block cursor-pointer">
                                <input type="file" accept="image/*" capture="environment" class="sr-only" @change="scanFile($event, 'single')">
                                <div class="rounded-card border-2 border-dashed border-titan-mint/40 bg-titan-mint/[0.07] active:bg-titan-mint/10 p-5 text-center">
                                    <div class="mx-auto h-10 w-10 rounded-chip bg-titan-mint/15 grid place-items-center mb-2">
                                        <svg class="h-5 w-5 text-titan-mint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    </div>
                                    <p class="font-display font-bold text-gray-100 text-sm" x-text="scanning ? 'Reading the label…' : 'Snap a bottle'"></p>
                                    <p class="text-[11px] text-gray-500 mt-0.5">I'll read the name, dose & brand off the label</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- ---- CONFIRM: dose + timing for a picked item (real form → store) ---- --}}
                    <div x-show="addView === 'confirm'">
                        <form method="POST" action="{{ route('stack.store') }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="brand" :value="draft.brand || ''">
                            <input type="hidden" name="dsld_id" :value="draft.dsld_id || ''">
                            <input type="hidden" name="rxcui" :value="draft.rxcui || ''">

                            <label class="block">
                                <span class="text-[11px] text-gray-500">Name</span>
                                <input type="text" name="name" x-model="draft.name" required
                                       class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                            </label>

                            <div class="grid grid-cols-3 gap-2">
                                <label class="block">
                                    <span class="text-[11px] text-gray-500">Dose</span>
                                    <input type="number" step="any" min="0" name="dose_amount" x-model="draft.dose_amount"
                                           class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] text-gray-500">Unit</span>
                                    <input type="text" name="dose_unit" x-model="draft.dose_unit" placeholder="mg"
                                           class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                </label>
                                <label class="block">
                                    <span class="text-[11px] text-gray-500">Form</span>
                                    <input type="text" name="form" x-model="draft.form" placeholder="softgel"
                                           class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-indigo-500 focus:ring-0">
                                </label>
                            </div>

                            <div>
                                <span class="text-[11px] text-gray-500">When</span>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    @foreach (['morning' => '☀ Morning', 'midday' => '🌤 Midday', 'evening' => '☾ Evening', 'night' => '🌙 Night', 'anytime' => '• Anytime'] as $slot => $lbl)
                                        <label class="cursor-pointer">
                                            <input type="checkbox" name="schedule[times][]" value="{{ $slot }}" class="peer sr-only" @checked($slot === 'morning')>
                                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $lbl }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                                <div>
                                    <span class="text-[11px] text-gray-500">Repeat</span>
                                    <div class="mt-1 flex gap-1.5">
                                        @foreach (['daily' => 'Daily', 'specific_days' => 'Set days', 'as_needed' => 'As needed'] as $fk => $flbl)
                                            <label class="cursor-pointer">
                                                <input type="radio" name="schedule[frequency]" value="{{ $fk }}" class="peer sr-only" @checked($fk === 'daily')>
                                                <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $flbl }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <label class="cursor-pointer">
                                    <input type="checkbox" name="schedule[with_food]" value="1" class="peer sr-only">
                                    <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">🍽 With food</span>
                                </label>
                            </div>

                            {{-- Type: only asked when the picked result didn't already carry a kind --}}
                            <div x-show="!draft.kindKnown">
                                <span class="text-[11px] text-gray-500">Type</span>
                                <div class="mt-1 flex gap-1.5">
                                    @foreach (['supplement' => 'Supplement', 'medication' => 'Medication', 'other' => 'Other'] as $kk => $klbl)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="kind" value="{{ $kk }}" class="peer sr-only" x-model="draft.kind">
                                            <span class="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs text-gray-300 peer-checked:border-indigo-500/50 peer-checked:bg-indigo-500/15 peer-checked:text-indigo-200">{{ $klbl }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <button type="submit" class="w-full rounded-chip bg-titan-mint hover:bg-titan-mint/90 px-4 py-2.5 text-sm font-semibold text-titan-bg">Save</button>
                        </form>
                    </div>

                    {{-- ---- SHELF: batch photo → confirm each ---- --}}
                    <div x-show="addView === 'shelf'" class="space-y-4">
                        <template x-if="candidates.length === 0">
                            <div>
                                <label class="block cursor-pointer">
                                    <input type="file" accept="image/*" capture="environment" class="sr-only" @change="scanFile($event, 'shelf')">
                                    <div class="rounded-card border-2 border-dashed border-titan-cyan/40 bg-titan-cyan/[0.07] active:bg-titan-cyan/10 p-7 text-center">
                                        <div class="mx-auto h-12 w-12 rounded-card bg-titan-cyan/15 grid place-items-center mb-3">
                                            <svg class="h-6 w-6 text-titan-cyan" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        </div>
                                        <p class="font-display font-bold text-gray-100" x-text="scanning ? 'Reading your shelf…' : 'Lay them out, take one photo'"></p>
                                        <p class="text-[11px] text-gray-500 mt-1">I'll pull every label I can read; you confirm each</p>
                                    </div>
                                </label>
                            </div>
                        </template>

                        <template x-if="candidates.length > 0">
                            <div class="space-y-3">
                                <p class="text-xs text-gray-500" x-text="shelfNote || 'Tap to leave anything out. Everything goes on for the morning, daily — fine-tune later by editing the item.'"></p>
                                {{-- Zero-typing: each label is an include/exclude row, included by default --}}
                                <template x-for="(c, i) in candidates" :key="i">
                                    <button type="button" @click="c.include = !c.include"
                                            class="w-full flex items-center gap-3 rounded-chip border border-white/5 bg-black/20 px-3.5 py-3 text-left transition active:bg-white/5"
                                            :class="!c.include && 'opacity-40'">
                                        <span class="grid place-items-center h-6 w-6 shrink-0 rounded-md border transition"
                                              :class="c.include ? 'border-titan-mint/60 bg-titan-mint/20 text-titan-mint' : 'border-white/20 text-transparent'">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                        <span class="flex-1 min-w-0">
                                            <span class="block text-sm font-medium text-gray-100 truncate" x-text="c.name + (c.dose_amount ? ' · ' + (c.dose_amount + ' ' + (c.dose_unit || '')).trim() : '')"></span>
                                            <span class="block text-[11px] text-gray-500 truncate" x-text="[c.brand, c.kind].filter(Boolean).join(' · ') || 'morning · daily'"></span>
                                        </span>
                                    </button>
                                </template>
                                <button type="button" @click="addShelf()" :disabled="busy || includedCount === 0"
                                        class="w-full rounded-chip bg-titan-mint hover:bg-titan-mint/90 disabled:opacity-50 px-3 py-2.5 text-sm font-semibold text-titan-bg"
                                        x-text="'Add ' + includedCount + ' to what you take'"></button>
                            </div>
                        </template>
                    </div>

                </div>
            </div>
        </div>

        {{-- Brief confirmation while the page refreshes after an add --}}
        <div x-show="justAdded" x-cloak x-transition.opacity class="fixed top-4 inset-x-0 z-[60] flex justify-center pointer-events-none">
            <div class="rounded-full bg-titan-mint px-4 py-2 text-sm font-semibold text-titan-bg shadow-lg">Added ✓</div>
        </div>
    </div>

    {{-- ============================================================= --}}
    {{-- Alpine controller — mirrors the layout's fetch + CSRF pattern  --}}
    {{-- ============================================================= --}}
    <script>
        function stackPage(today) {
            return {
                today,
                busy: false,
                csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
                expanded: {},

                // add sheet
                addOpen: false,
                addView: 'home',
                query: '',
                results: [],
                searching: false,
                scanning: false,
                draft: {},
                candidates: [],
                shelfNote: '',
                addedAny: false,
                justAdded: false,

                init() {
                    const nowHour = new Date().getHours();
                    const starts = { morning: 5, midday: 11, evening: 16, night: 20, anytime: 0 };
                    (this.today.slots || []).forEach(s => {
                        if (this.expanded[s.key] === undefined) {
                            // collapse purely-upcoming slots so morning-you isn't shown the evening list
                            this.expanded[s.key] = nowHour >= (starts[s.key] ?? 0);
                        }
                    });
                },

                // ---- checklist ----
                slotIcon(k) { return { morning: '☀', midday: '🌤', evening: '☾', night: '🌙', anytime: '•' }[k] || '•'; },
                // Upcoming slots stay hidden entirely until their hour (matches iOS); morning/midday/anytime always show.
                slotVisible(s) {
                    const reveal = { evening: 16, night: 20 };
                    const h = reveal[s.key];
                    return h === undefined || new Date().getHours() >= h;
                },
                untaken(s) { return s.items.filter(i => !i.taken).length; },
                isOpen(s) { return this.expanded[s.key] !== false; },
                toggle(s) { this.expanded[s.key] = !this.isOpen(s); },
                get includedCount() { return this.candidates.filter(c => c.include).length; },

                async take(item) {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        const res = await fetch('/stack/' + item.id + '/intake', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ status: 'taken', slot: item.slot }),
                            credentials: 'same-origin',
                        });
                        if (res.ok) { const d = await res.json(); if (d.today) this.today = d.today; }
                    } catch (e) { /* offline — leave state */ } finally { this.busy = false; }
                },
                async undo(item) {
                    if (this.busy || !item.event_id) return;
                    this.busy = true;
                    try {
                        const res = await fetch('/stack/intake/' + item.event_id, {
                            method: 'DELETE',
                            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (res.ok) { const d = await res.json(); if (d.today) this.today = d.today; }
                    } catch (e) { /* */ } finally { this.busy = false; }
                },

                // ---- add sheet ----
                openAdd() { this.addOpen = true; this.addView = 'home'; },
                back() {
                    if (this.addView === 'confirm') { this.addView = 'one'; return; }
                    this.addView = 'home';
                },
                closeAdd() {
                    this.addOpen = false;
                    if (this.addedAny) {
                        // Make the refresh feel intentional: brief "Added ✓" before the page reloads.
                        this.justAdded = true;
                        setTimeout(() => window.location.reload(), 600);
                        return;
                    }
                    // reset for next time
                    this.addView = 'home'; this.query = ''; this.results = [];
                    this.candidates = []; this.shelfNote = ''; this.draft = {};
                },

                async search() {
                    const q = this.query.trim();
                    if (q.length < 2) { this.results = []; return; }
                    this.searching = true;
                    try {
                        const res = await fetch('/stack/search?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        const d = await res.json();
                        this.results = d.results || [];
                    } catch (e) { this.results = []; } finally { this.searching = false; }
                },

                async scanFile(e, mode) {
                    const f = e.target.files[0];
                    if (!f) return;
                    const fd = new FormData();
                    fd.append('photo', f);
                    fd.append('mode', mode);
                    this.scanning = true;
                    try {
                        const res = await fetch('/stack/scan', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                            body: fd, credentials: 'same-origin',
                        });
                        const d = await res.json();
                        if (mode === 'shelf') {
                            this.shelfNote = d.note || '';
                            this.candidates = (d.candidates || []).map(c => ({
                                ...c, include: true, // included by default; sensible morning · daily applied on save
                            }));
                        } else {
                            this.results = d.candidates || [];
                            if (this.results.length === 1) this.pick(this.results[0]);
                        }
                    } catch (err) { alert('Could not read that photo. Try again or add it by hand.'); } finally { this.scanning = false; e.target.value = ''; }
                },

                pick(r) {
                    this.draft = {
                        name: r.name || '', brand: r.brand || '', dose_amount: r.dose_amount ?? '',
                        dose_unit: r.dose_unit || '', form: r.form || '', kind: r.kind || 'supplement',
                        dsld_id: r.dsld_id || '', rxcui: r.rxcui || '',
                        kindKnown: !!r.kind, // hide the Type picker when the result already told us what it is
                    };
                    this.addView = 'confirm';
                },
                pickManual() {
                    this.draft = { name: this.query.trim(), brand: '', dose_amount: '', dose_unit: '', form: '', kind: 'supplement', dsld_id: '', rxcui: '', kindKnown: false };
                    this.addView = 'confirm';
                },

                // Save every included candidate in one go, with the default schedule (morning · daily).
                async addShelf() {
                    if (this.busy) return;
                    const picks = this.candidates.filter(c => c.include);
                    if (picks.length === 0) return;
                    this.busy = true;
                    try {
                        for (const c of picks) {
                            const fd = new FormData();
                            fd.append('name', c.name || '');
                            if (c.brand) fd.append('brand', c.brand);
                            if (c.dose_amount !== '' && c.dose_amount != null) fd.append('dose_amount', c.dose_amount);
                            if (c.dose_unit) fd.append('dose_unit', c.dose_unit);
                            if (c.form) fd.append('form', c.form);
                            fd.append('kind', c.kind || 'supplement');
                            fd.append('schedule[frequency]', 'daily');
                            fd.append('schedule[times][]', 'morning');
                            fd.append('schedule[with_food]', 0);
                            const res = await fetch('/stack', {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                                body: fd, credentials: 'same-origin',
                            });
                            if (res.ok) this.addedAny = true;
                        }
                    } catch (e) { /* */ } finally { this.busy = false; }
                    this.closeAdd();
                },
            };
        }
    </script>
</x-titan-layout>
