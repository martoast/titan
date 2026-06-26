<?php

namespace App\Services\Stack;

use App\Models\Profile;
use App\Models\StackItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Worth knowing" about a profile's active stack — pairwise findings across drug↔drug,
 * drug↔supplement and supplement↔supplement. A curated seed map of well-established
 * interactions/timing notes is the reliable backbone; an openFDA label lookup augments
 * drug↔drug pairs best-effort. Everything is INFORMATIONAL (literature/label data + a source),
 * never a recommendation — the caller pins a "not medical advice" disclaimer.
 *
 * Recompute (idempotent) whenever the stack changes; results cache into interaction_flags.
 */
class InteractionChecker
{
    /** Cap on live openFDA calls per refresh — bounds worst-case request latency (each is cached after). */
    private const MAX_FDA_CALLS = 10;

    /** Recompute and persist flags for the profile's active stack. @return Collection<int,\App\Models\InteractionFlag> */
    public function refresh(Profile $profile): Collection
    {
        $items = $profile->stackItems()->where('active', true)->get();
        $now = Carbon::now();

        // Compute the FULL new snapshot first (including any network lookups) BEFORE touching the DB,
        // so a slow/failed openFDA call can never leave the profile with zero flags — the old snapshot
        // survives until we atomically swap it in below.
        $found = [];
        $list = $items->values();
        $fdaCalls = 0;
        for ($i = 0; $i < $list->count(); $i++) {
            for ($j = $i + 1; $j < $list->count(); $j++) {
                $a = $list[$i];
                $b = $list[$j];
                $hit = $this->seedMatch($a, $b);
                if (! $hit && $a->kind === 'medication' && $b->kind === 'medication' && $fdaCalls < self::MAX_FDA_CALLS) {
                    $fdaCalls++;
                    $hit = $this->openFdaMatch($a, $b);
                }
                if ($hit) {
                    $found[] = [
                        'profile_id' => $profile->id,
                        'a_item_id' => $a->id,
                        'b_item_id' => $b->id,
                        'a_name' => $a->name,
                        'b_name' => $b->name,
                        'severity' => $hit['severity'],
                        'summary' => $hit['summary'],
                        'source' => $hit['source'],
                        'checked_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // Atomic swap: the wipe + insert happen together so concurrent reads (and a mid-refresh
        // failure) never observe an empty flag set on a stack that has real interactions.
        DB::transaction(function () use ($profile, $found) {
            $profile->interactionFlags()->delete();
            if ($found !== []) {
                \App\Models\InteractionFlag::insert($found);
            }
        });

        return $profile->interactionFlags()
            ->get()
            ->sortBy(fn ($f) => $f->rank())
            ->values();
    }

    // ---- seed map ----------------------------------------------------------

    /** @return array{severity:string,summary:string,source:string}|null */
    private function seedMatch(StackItem $a, StackItem $b): ?array
    {
        $ka = $this->canonical($a->name);
        $kb = $this->canonical($b->name);
        foreach (self::SEED as $rule) {
            [$x, $y] = $rule['pair'];
            if (($ka === $x && $kb === $y) || ($ka === $y && $kb === $x)) {
                return ['severity' => $rule['severity'], 'summary' => $rule['summary'], 'source' => 'seed'];
            }
        }

        return null;
    }

    /** Map a product/ingredient name onto a canonical key the seed map is written against. */
    private function canonical(string $name): string
    {
        $n = Str::lower($name);
        foreach (self::ALIASES as $key => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($n, $needle)) {
                    return $key;
                }
            }
        }

        return Str::of($n)->trim()->value();
    }

    // ---- openFDA fallback (drug↔drug, best-effort) -------------------------

    /** @return array{severity:string,summary:string,source:string}|null */
    private function openFdaMatch(StackItem $a, StackItem $b): ?array
    {
        if ($a->kind !== 'medication' || $b->kind !== 'medication') {
            return null;   // seed covers supplement pairs; openFDA labels are drug-centric
        }
        $ka = $this->canonical($a->name);
        $kb = $this->canonical($b->name);

        // Cache the RESOLVED result per drug pair (a hit, or a confirmed no-interaction) for a week.
        // Only a successful lookup is cached — a transient timeout/5xx returns null WITHOUT caching, so
        // one blip never suppresses a real flag for days (unlike a naive Cache::remember around the call).
        $pair = [$ka, $kb];
        sort($pair);
        $cacheKey = 'stack:fda:'.implode('|', $pair);
        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached['hit'] ?? null;
        }

        try {
            $res = Http::timeout(4)->acceptJson()->get('https://api.fda.gov/drug/label.json', [
                'search' => 'openfda.generic_name:"'.$ka.'"',
                'limit' => 1,
            ]);
            if (! $res->ok()) {
                return null;   // transient — do not cache
            }
            $text = Str::lower((string) data_get($res->json(), 'results.0.drug_interactions.0', ''));
            $hit = ($text !== '' && str_contains($text, $kb)) ? [
                'severity' => 'moderate',
                'summary' => 'An interaction between these two is noted on the FDA drug label. Worth asking your pharmacist about.',
                'source' => 'openFDA label',
            ] : null;
            Cache::put($cacheKey, ['hit' => $hit], Carbon::now()->addDays(7));

            return $hit;
        } catch (\Throwable) {
            return null;   // best-effort only — do not cache a failure
        }
    }

    /** Canonical-key aliases: many product names → one ingredient key. */
    private const ALIASES = [
        'magnesium' => ['magnesium', 'mag '],
        'calcium' => ['calcium'],
        'iron' => ['iron', 'ferrous'],
        'zinc' => ['zinc'],
        'copper' => ['copper'],
        'potassium' => ['potassium'],
        'vitamin d' => ['vitamin d', 'd3', 'cholecalciferol'],
        'vitamin k' => ['vitamin k', 'k2', 'mk-7', 'menaquinone'],
        'vitamin c' => ['vitamin c', 'ascorbic'],
        'omega-3' => ['omega', 'fish oil', 'epa', 'dha'],
        'berberine' => ['berberine'],
        'lisinopril' => ['lisinopril'],
        'losartan' => ['losartan', 'cozaar'],
        'levothyroxine' => ['levothyroxine', 'synthroid'],
        'metformin' => ['metformin', 'glucophage'],
        'warfarin' => ['warfarin', 'coumadin'],
        'aspirin' => ['aspirin', 'asa'],
        'ibuprofen' => ['ibuprofen', 'advil', 'motrin'],
        'omeprazole' => ['omeprazole', 'prilosec'],
    ];

    /** Well-established, plainly-worded pairs. Severity: major | moderate | timing | info. */
    private const SEED = [
        ['pair' => ['calcium', 'iron'], 'severity' => 'timing', 'summary' => 'Calcium and iron compete for absorption — taking them a couple of hours apart helps both work better.'],
        ['pair' => ['calcium', 'levothyroxine'], 'severity' => 'moderate', 'summary' => 'Calcium can reduce how much thyroid medication you absorb. They are usually separated by about 4 hours.'],
        ['pair' => ['iron', 'levothyroxine'], 'severity' => 'moderate', 'summary' => 'Iron can reduce levothyroxine absorption — typically taken several hours apart.'],
        ['pair' => ['zinc', 'copper'], 'severity' => 'timing', 'summary' => 'Higher-dose zinc over time can lower copper levels — many balanced supplements pair the two.'],
        ['pair' => ['zinc', 'iron'], 'severity' => 'timing', 'summary' => 'Zinc and iron can compete for absorption when taken together in large doses.'],
        ['pair' => ['magnesium', 'lisinopril'], 'severity' => 'moderate', 'summary' => 'Reported to enhance the blood-pressure-lowering effect. Something to be aware of with your clinician.'],
        ['pair' => ['magnesium', 'levothyroxine'], 'severity' => 'timing', 'summary' => 'Magnesium can reduce thyroid-medication absorption — usually spaced a few hours apart.'],
        ['pair' => ['potassium', 'lisinopril'], 'severity' => 'moderate', 'summary' => 'ACE inhibitors can raise potassium; added potassium may add to that. Worth flagging to your clinician.'],
        ['pair' => ['potassium', 'losartan'], 'severity' => 'moderate', 'summary' => 'ARBs can raise potassium; added potassium may add to that. Worth flagging to your clinician.'],
        ['pair' => ['vitamin k', 'warfarin'], 'severity' => 'major', 'summary' => 'Vitamin K can counteract warfarin and affects its dosing. Keep intake steady and tell your prescriber.'],
        ['pair' => ['omega-3', 'warfarin'], 'severity' => 'moderate', 'summary' => 'May add to blood-thinning effects. Reported in the literature — discuss with your prescriber.'],
        ['pair' => ['omega-3', 'aspirin'], 'severity' => 'moderate', 'summary' => 'Both can thin the blood; combined they may add up. Something to be aware of.'],
        ['pair' => ['berberine', 'metformin'], 'severity' => 'moderate', 'summary' => 'Both lower blood sugar — the effect may be additive. Worth monitoring with your clinician.'],
        ['pair' => ['vitamin d', 'magnesium'], 'severity' => 'info', 'summary' => 'Magnesium is a cofactor in vitamin D metabolism — they are commonly taken together.'],
        ['pair' => ['vitamin d', 'vitamin k'], 'severity' => 'info', 'summary' => 'Often paired — vitamin K2 helps direct the calcium that vitamin D helps absorb.'],
    ];
}
