<x-titan-layout title="Meals" subtitle="Review & correct before saving — AI meal estimates run ~10–25% off">
    @php
        $photoUrl = $draft['photo_path'] ? \Illuminate\Support\Facades\Storage::disk('public')->url($draft['photo_path']) : null;
    @endphp

    <div class="max-w-3xl mx-auto">
        <div class="mb-5 flex items-center gap-3">
            <a href="/meals" class="grid h-9 w-9 place-items-center rounded-chip border border-white/10 bg-white/[0.03] text-gray-400 active:bg-white/10">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="font-display text-xl font-bold text-gray-100">{{ $heading }}</h2>
        </div>

        @if (!empty($aiError))
            <div class="mb-5 rounded-chip border border-titan-amber/20 bg-titan-amber/10 px-4 py-3 text-sm text-titan-amber">
                {{ $aiError }}
            </div>
        @elseif (!empty($draft['assumptions']))
            <div class="mb-5 rounded-chip border border-titan-indigo/20 bg-titan-indigo/10 px-4 py-3 text-sm text-indigo-200">
                <span class="font-medium">AI note:</span> {{ $draft['assumptions'] }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-chip border border-titan-pink/20 bg-titan-pink/10 px-4 py-3 text-sm text-titan-pink">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="/meals"
              x-data="mealForm(@js($draft['items']))">
            @csrf
            <input type="hidden" name="source" value="{{ $draft['source'] }}">
            <input type="hidden" name="photo_path" value="{{ $draft['photo_path'] }}">

            <x-card pad="p-5" class="mb-5">
                <div class="flex gap-4">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="" class="h-24 w-24 rounded-chip object-cover shrink-0 bg-gray-950">
                    @endif
                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs text-gray-500">Meal name</span>
                            <input type="text" name="name" value="{{ old('name', $draft['name']) }}" placeholder="e.g. Breakfast"
                                   class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 placeholder-gray-600 focus:border-titan-amber/60 focus:ring-0">
                        </label>
                        <label class="block">
                            <span class="text-xs text-gray-500">Eaten at</span>
                            <input type="datetime-local" name="eaten_at" value="{{ old('eaten_at', $draft['eaten_at']) }}"
                                   class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 focus:border-titan-amber/60 focus:ring-0">
                        </label>
                    </div>
                </div>
            </x-card>

            {{-- Editable items --}}
            <x-card pad="p-5" class="mb-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-[0.65rem] font-bold uppercase tracking-[0.14em] text-gray-500">Ingredients</h3>
                    <button type="button" @click="addItem()" class="text-xs font-semibold text-titan-amber active:opacity-80">+ Add item</button>
                </div>

                {{-- Header (desktop) --}}
                <div class="hidden md:grid grid-cols-12 gap-2 px-1 pb-2 text-[11px] uppercase tracking-wide text-gray-600">
                    <div class="col-span-3">Food</div>
                    <div class="col-span-2">Qty</div>
                    <div class="col-span-2">Cal</div>
                    <div class="col-span-1">P</div>
                    <div class="col-span-1">C</div>
                    <div class="col-span-1">F</div>
                    <div class="col-span-2"></div>
                </div>

                <div class="space-y-2">
                    <template x-for="(item, i) in items" :key="i">
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <input type="text" :name="`items[${i}][name]`" x-model="item.name" placeholder="Food"
                                   class="col-span-12 md:col-span-3 rounded-lg bg-gray-950 border border-white/10 px-2.5 py-1.5 text-sm text-gray-100 placeholder-gray-600 focus:border-titan-amber/60 focus:ring-0">
                            <input type="text" :name="`items[${i}][quantity]`" x-model="item.quantity" placeholder="1 cup"
                                   class="col-span-6 md:col-span-2 rounded-lg bg-gray-950 border border-white/10 px-2.5 py-1.5 text-sm text-gray-100 placeholder-gray-600 focus:border-titan-amber/60 focus:ring-0">
                            <input type="number" min="0" step="1" :name="`items[${i}][calories]`" x-model.number="item.calories" placeholder="0"
                                   class="col-span-6 md:col-span-2 rounded-lg bg-gray-950 border border-white/10 px-2.5 py-1.5 text-sm text-gray-100 focus:border-titan-amber/60 focus:ring-0">
                            <input type="number" min="0" step="0.1" :name="`items[${i}][protein_g]`" x-model.number="item.protein_g" placeholder="0"
                                   class="col-span-4 md:col-span-1 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-titan-amber/60 focus:ring-0">
                            <input type="number" min="0" step="0.1" :name="`items[${i}][carbs_g]`" x-model.number="item.carbs_g" placeholder="0"
                                   class="col-span-4 md:col-span-1 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-titan-amber/60 focus:ring-0">
                            <input type="number" min="0" step="0.1" :name="`items[${i}][fat_g]`" x-model.number="item.fat_g" placeholder="0"
                                   class="col-span-3 md:col-span-1 rounded-lg bg-gray-950 border border-white/10 px-2 py-1.5 text-sm text-gray-100 focus:border-titan-amber/60 focus:ring-0">
                            <button type="button" @click="removeItem(i)" class="col-span-1 grid place-items-center text-gray-600 hover:text-rose-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </template>
                </div>

                {{-- Live totals --}}
                <div class="mt-4 pt-4 border-t border-white/5 flex flex-wrap gap-x-6 gap-y-1 text-sm nums">
                    <span class="text-gray-400">Total:</span>
                    <span class="text-gray-100 font-medium" x-text="totals.calories.toLocaleString() + ' kcal'"></span>
                    <span class="text-titan-mint" x-text="'P ' + round(totals.protein_g) + 'g'"></span>
                    <span class="text-titan-amber" x-text="'C ' + round(totals.carbs_g) + 'g'"></span>
                    <span class="text-titan-pink" x-text="'F ' + round(totals.fat_g) + 'g'"></span>
                </div>
            </x-card>

            <label class="block mb-5">
                <span class="text-xs text-gray-500">Notes</span>
                <textarea name="notes" rows="2" placeholder="Optional"
                          class="mt-1 w-full rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-sm text-gray-100 placeholder-gray-600 focus:border-titan-amber/60 focus:ring-0">{{ old('notes', $draft['notes']) }}</textarea>
            </label>

            <div class="flex items-center justify-end gap-3">
                <a href="/meals" class="text-sm text-gray-400 hover:text-gray-200 px-4 py-2">Cancel</a>
                <button type="submit" class="rounded-chip bg-titan-amber px-5 py-2.5 text-sm font-bold text-gray-950 active:opacity-90">Save meal</button>
            </div>
        </form>
    </div>

    <script>
        function mealForm(initialItems) {
            return {
                items: (initialItems && initialItems.length ? initialItems : [{ name: '', quantity: '', calories: 0, protein_g: 0, carbs_g: 0, fat_g: 0 }])
                    .map(i => ({
                        name: i.name ?? '', quantity: i.quantity ?? '',
                        calories: Number(i.calories) || 0, protein_g: Number(i.protein_g) || 0,
                        carbs_g: Number(i.carbs_g) || 0, fat_g: Number(i.fat_g) || 0,
                    })),
                addItem() { this.items.push({ name: '', quantity: '', calories: 0, protein_g: 0, carbs_g: 0, fat_g: 0 }); },
                removeItem(i) { this.items.splice(i, 1); if (!this.items.length) this.addItem(); },
                round(n) { return Math.round((Number(n) || 0) * 10) / 10; },
                get totals() {
                    return this.items.reduce((t, i) => ({
                        calories: t.calories + (Number(i.calories) || 0),
                        protein_g: t.protein_g + (Number(i.protein_g) || 0),
                        carbs_g: t.carbs_g + (Number(i.carbs_g) || 0),
                        fat_g: t.fat_g + (Number(i.fat_g) || 0),
                    }), { calories: 0, protein_g: 0, carbs_g: 0, fat_g: 0 });
                },
            };
        }
    </script>
</x-titan-layout>
