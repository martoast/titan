<?php

namespace App\Services\Stack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Search for something to add to "What you take". Resolves a typed query into structured
 * candidates (name, brand, dose, form, kind) with a stable catalog id so interaction checks
 * have something to match on later:
 *   - supplements → NIH DSLD (api.ods.od.nih.gov, CC0 public domain, no key needed)
 *   - medications → RxNorm (rxnav.nlm.nih.gov) → an rxcui
 *
 * A built-in list of common items answers instantly (and keeps search working offline / in
 * tests); live results from DSLD/RxNorm are merged in best-effort on top.
 */
class SupplementCatalog
{
    private const DSLD = 'https://api.ods.od.nih.gov/dsld/v9/search-filter';
    private const RXNORM = 'https://rxnav.nlm.nih.gov/REST/approximateTerm.json';

    /**
     * @return array<int,array<string,mixed>> candidates: {name, brand?, dose_amount?, dose_unit?, form?, kind, dsld_id?, rxcui?, source}
     */
    public function search(string $query, int $limit = 8): array
    {
        $q = trim($query);
        if (Str::length($q) < 2) {
            return [];
        }

        $hits = $this->builtin($q);

        // Best-effort live augmentation (cached, short timeout). Never let the network break search.
        foreach ([...$this->dsld($q), ...$this->rxnorm($q)] as $row) {
            if (! $this->seen($hits, $row['name'])) {
                $hits[] = $row;
            }
        }

        return array_slice($hits, 0, $limit);
    }

    // ---- built-in common items (instant + offline) -------------------------

    /** @return array<int,array<string,mixed>> */
    private function builtin(string $q): array
    {
        $needle = Str::lower($q);
        $out = [];
        foreach (self::COMMON as $row) {
            $hay = Str::lower($row['name'].' '.($row['brand'] ?? '').' '.implode(' ', $row['syn'] ?? []));
            if (str_contains($hay, $needle) || str_contains($needle, Str::lower($row['name']))) {
                $out[] = [
                    'name' => $row['name'],
                    'brand' => $row['brand'] ?? null,
                    'dose_amount' => $row['dose_amount'] ?? null,
                    'dose_unit' => $row['dose_unit'] ?? null,
                    'form' => $row['form'] ?? null,
                    'kind' => $row['kind'],
                    'source' => 'builtin',
                ];
            }
        }

        // Exact / prefix matches first.
        usort($out, fn ($a, $b) => $this->score($b['name'], $needle) <=> $this->score($a['name'], $needle));

        return $out;
    }

    private function score(string $name, string $needle): int
    {
        $n = Str::lower($name);

        return $n === $needle ? 3 : (str_starts_with($n, $needle) ? 2 : 1);
    }

    // ---- DSLD (supplements) ------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function dsld(string $q): array
    {
        $key = 'stack.dsld.'.md5(Str::lower($q));
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        try {
            $res = Http::timeout(4)->acceptJson()->get(self::DSLD, ['q' => $q, 'size' => 6]);
            if (! $res->ok()) {
                return [];   // transient — don't cache a failure for a day
            }
            $hits = $res->json('hits.hits') ?? $res->json('hits') ?? [];
            $out = [];
            foreach ($hits as $h) {
                $s = $h['_source'] ?? $h;
                $name = $s['fullName'] ?? $s['productName'] ?? $s['name'] ?? null;
                if (! $name) {
                    continue;
                }
                $out[] = [
                    'name' => Str::limit((string) $name, 80, ''),
                    'brand' => $s['brandName'] ?? $s['brand'] ?? null,
                    'dose_amount' => null,
                    'dose_unit' => null,
                    'form' => $s['physicalState']['langualCodeDescription'] ?? null,
                    'kind' => 'supplement',
                    'dsld_id' => (string) ($h['_id'] ?? $s['id'] ?? ''),
                    'source' => 'dsld',
                ];
            }
            Cache::put($key, $out, now()->addDay());   // cache only a successful response

            return $out;
        } catch (\Throwable) {
            return [];   // transient — don't cache
        }
    }

    // ---- RxNorm (medications) ----------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function rxnorm(string $q): array
    {
        $key = 'stack.rxnorm.'.md5(Str::lower($q));
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        try {
            $res = Http::timeout(4)->acceptJson()->get(self::RXNORM, ['term' => $q, 'maxEntries' => 5]);
            if (! $res->ok()) {
                return [];   // transient — don't cache a failure for a day
            }
            $cands = $res->json('approximateGroup.candidate') ?? [];
            $out = [];
            $seenRxcui = [];
            foreach ($cands as $c) {
                $rxcui = (string) ($c['rxcui'] ?? '');
                $name = $c['name'] ?? null;
                if (! $rxcui || ! $name || isset($seenRxcui[$rxcui])) {
                    continue;
                }
                $seenRxcui[$rxcui] = true;
                $out[] = [
                    'name' => Str::limit((string) $name, 80, ''),
                    'brand' => null,
                    'dose_amount' => null,
                    'dose_unit' => null,
                    'form' => null,
                    'kind' => 'medication',
                    'rxcui' => $rxcui,
                    'source' => 'rxnorm',
                ];
            }
            Cache::put($key, $out, now()->addDay());   // cache only a successful response

            return $out;
        } catch (\Throwable) {
            return [];   // transient — don't cache
        }
    }

    /** @param  array<int,array<string,mixed>>  $rows */
    private function seen(array $rows, string $name): bool
    {
        $n = Str::lower(trim($name));
        foreach ($rows as $r) {
            if (Str::lower(trim((string) $r['name'])) === $n) {
                return true;
            }
        }

        return false;
    }

    /** A small, high-frequency catalog so the common case is instant and works offline. */
    private const COMMON = [
        ['name' => 'Vitamin D3', 'kind' => 'supplement', 'dose_amount' => 5000, 'dose_unit' => 'IU', 'form' => 'softgel', 'syn' => ['d3', 'cholecalciferol', 'vitamin d']],
        ['name' => 'Magnesium Glycinate', 'kind' => 'supplement', 'dose_amount' => 400, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['magnesium', 'mag', 'glycinate']],
        ['name' => 'Magnesium Citrate', 'kind' => 'supplement', 'dose_amount' => 200, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['magnesium', 'mag', 'citrate']],
        ['name' => 'Omega-3 Fish Oil', 'kind' => 'supplement', 'dose_amount' => 1000, 'dose_unit' => 'mg', 'form' => 'softgel', 'syn' => ['fish oil', 'omega 3', 'epa', 'dha']],
        ['name' => 'Creatine Monohydrate', 'kind' => 'supplement', 'dose_amount' => 5, 'dose_unit' => 'g', 'form' => 'powder', 'syn' => ['creatine']],
        ['name' => 'Zinc', 'kind' => 'supplement', 'dose_amount' => 25, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['zinc picolinate']],
        ['name' => 'Vitamin C', 'kind' => 'supplement', 'dose_amount' => 1000, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['ascorbic acid']],
        ['name' => 'Vitamin B12', 'kind' => 'supplement', 'dose_amount' => 1000, 'dose_unit' => 'mcg', 'form' => 'tablet', 'syn' => ['b12', 'methylcobalamin', 'cobalamin']],
        ['name' => 'Vitamin B Complex', 'kind' => 'supplement', 'form' => 'capsule', 'syn' => ['b complex', 'b vitamins']],
        ['name' => 'Multivitamin', 'kind' => 'supplement', 'form' => 'tablet', 'syn' => ['multi', 'multi vitamin']],
        ['name' => 'Iron', 'kind' => 'supplement', 'dose_amount' => 18, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['ferrous sulfate', 'ferrous']],
        ['name' => 'Calcium', 'kind' => 'supplement', 'dose_amount' => 600, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['calcium carbonate', 'calcium citrate']],
        ['name' => 'Vitamin K2', 'kind' => 'supplement', 'dose_amount' => 100, 'dose_unit' => 'mcg', 'form' => 'capsule', 'syn' => ['k2', 'mk-7', 'menaquinone']],
        ['name' => 'Probiotic', 'kind' => 'supplement', 'form' => 'capsule', 'syn' => ['probiotics']],
        ['name' => 'Ashwagandha', 'kind' => 'supplement', 'dose_amount' => 600, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['ksm-66', 'withania']],
        ['name' => 'Melatonin', 'kind' => 'supplement', 'dose_amount' => 3, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => []],
        ['name' => 'L-Theanine', 'kind' => 'supplement', 'dose_amount' => 200, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['theanine']],
        ['name' => 'Collagen Peptides', 'kind' => 'supplement', 'dose_amount' => 10, 'dose_unit' => 'g', 'form' => 'powder', 'syn' => ['collagen']],
        ['name' => 'Curcumin', 'kind' => 'supplement', 'dose_amount' => 500, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['turmeric']],
        ['name' => 'CoQ10', 'kind' => 'supplement', 'dose_amount' => 100, 'dose_unit' => 'mg', 'form' => 'softgel', 'syn' => ['coenzyme q10', 'ubiquinol']],
        ['name' => 'Berberine', 'kind' => 'supplement', 'dose_amount' => 500, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => []],
        ['name' => 'Electrolytes', 'kind' => 'supplement', 'form' => 'powder', 'syn' => ['lmnt', 'electrolyte']],
        ['name' => 'Potassium', 'kind' => 'supplement', 'dose_amount' => 99, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => []],
        // Common medications (kind=medication) — RxNorm fills the rxcui live when available.
        ['name' => 'Lisinopril', 'kind' => 'medication', 'dose_amount' => 10, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => []],
        ['name' => 'Atorvastatin', 'kind' => 'medication', 'dose_amount' => 20, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['lipitor']],
        ['name' => 'Metformin', 'kind' => 'medication', 'dose_amount' => 500, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['glucophage']],
        ['name' => 'Levothyroxine', 'kind' => 'medication', 'dose_amount' => 50, 'dose_unit' => 'mcg', 'form' => 'tablet', 'syn' => ['synthroid', 'levo']],
        ['name' => 'Omeprazole', 'kind' => 'medication', 'dose_amount' => 20, 'dose_unit' => 'mg', 'form' => 'capsule', 'syn' => ['prilosec']],
        ['name' => 'Ibuprofen', 'kind' => 'medication', 'dose_amount' => 200, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['advil', 'motrin']],
        ['name' => 'Aspirin', 'kind' => 'medication', 'dose_amount' => 81, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['asa', 'baby aspirin']],
        ['name' => 'Sertraline', 'kind' => 'medication', 'dose_amount' => 50, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['zoloft']],
        ['name' => 'Losartan', 'kind' => 'medication', 'dose_amount' => 50, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['cozaar']],
        ['name' => 'Amlodipine', 'kind' => 'medication', 'dose_amount' => 5, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['norvasc']],
        ['name' => 'Metoprolol', 'kind' => 'medication', 'dose_amount' => 50, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => []],
        ['name' => 'Warfarin', 'kind' => 'medication', 'dose_amount' => 5, 'dose_unit' => 'mg', 'form' => 'tablet', 'syn' => ['coumadin']],
    ];
}
