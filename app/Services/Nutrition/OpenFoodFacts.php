<?php

namespace App\Services\Nutrition;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Open Food Facts — the free, open, no-key global food database. Maps a scanned barcode (GTIN/EAN/UPC)
 * to a product's brand, name, and macros. We read per-100g (always present) and the per-serving values
 * (when the label gave them). Returns null on a miss / unknown product so callers can fall back.
 */
class OpenFoodFacts
{
    private const BASE = 'https://world.openfoodfacts.org/api/v2/product/';

    /**
     * @return array{
     *   barcode:string, name:string, brand:?string, image_url:?string, serving:?string,
     *   per_100g:array{calories:int,protein_g:float,carbs_g:float,fat_g:float},
     *   per_serving:?array{calories:int,protein_g:float,carbs_g:float,fat_g:float}
     * }|null
     */
    public function lookup(string $barcode): ?array
    {
        $barcode = preg_replace('/\D/', '', $barcode) ?? '';
        if (strlen($barcode) < 8 || strlen($barcode) > 14) {
            return null;
        }

        try {
            $resp = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Titan/1.0 (self-hosted health app)'])
                ->get(self::BASE.$barcode.'.json', [
                    'fields' => 'product_name,brands,serving_size,nutriments,image_front_small_url',
                ]);
        } catch (\Throwable $e) {
            Log::warning('[OpenFoodFacts] request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $resp->ok() || (int) $resp->json('status') !== 1) {
            return null;   // 0 = product not found in the database
        }

        $p = (array) $resp->json('product');
        $n = (array) ($p['nutriments'] ?? []);

        $per100 = $this->macros($n, '_100g');
        if ($per100 === null) {
            return null;   // no usable nutrition on this product
        }

        $name = trim((string) ($p['product_name'] ?? ''));
        $brand = trim((string) Str::of((string) ($p['brands'] ?? ''))->before(','));   // first listed brand

        return [
            'barcode' => $barcode,
            'name' => $name !== '' ? Str::limit($name, 80, '') : ($brand ?: 'Scanned product'),
            'brand' => $brand !== '' ? $brand : null,
            'image_url' => ($img = trim((string) ($p['image_front_small_url'] ?? ''))) !== '' ? $img : null,
            'serving' => ($s = trim((string) ($p['serving_size'] ?? ''))) !== '' ? Str::limit($s, 60, '') : null,
            'per_100g' => $per100,
            'per_serving' => $this->macros($n, '_serving'),
        ];
    }

    /**
     * Pull a macro set for a basis suffix ("_100g" or "_serving") from OFF's `nutriments`. Energy is
     * preferred in kcal; falls back to converting kJ. Returns null if there's no calorie figure.
     *
     * @param  array<string,mixed>  $n
     * @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float}|null
     */
    private function macros(array $n, string $basis): ?array
    {
        $kcal = $n['energy-kcal'.$basis] ?? null;
        if (! is_numeric($kcal)) {
            $kj = $n['energy'.$basis] ?? $n['energy-kj'.$basis] ?? null;   // some products only carry kJ
            $kcal = is_numeric($kj) ? (float) $kj / 4.184 : null;
        }
        if (! is_numeric($kcal)) {
            return null;
        }

        return [
            'calories' => (int) round((float) $kcal),
            'protein_g' => round((float) ($n['proteins'.$basis] ?? 0), 1),
            'carbs_g' => round((float) ($n['carbohydrates'.$basis] ?? 0), 1),
            'fat_g' => round((float) ($n['fat'.$basis] ?? 0), 1),
        ];
    }
}
