<?php

namespace App\Models;

use App\Support\MealMemory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached packaged-product nutrition label (per serving). Written once — the first time a branded item
 * is researched on the web or read straight off a nutrition-facts photo — then reused on every later
 * scan of the same product, so branded lookups are instant + free after the first. Shared across users
 * (a label is universal). Keyed on a normalized "brand product" string.
 */
class BrandedFood extends Model
{
    protected $table = 'branded_foods';   // Eloquent treats "food" as uncountable → be explicit

    protected $fillable = [
        'barcode', 'key', 'brand', 'product', 'serving',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'source', 'hits',
    ];

    protected function casts(): array
    {
        return [
            'calories' => 'integer',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'hits' => 'integer',
        ];
    }

    /** Dedup/lookup key for a product — brand + product, normalized. Empty when neither is known. */
    public static function keyFor(?string $brand, ?string $product): string
    {
        return MealMemory::normalize(trim((string) $brand.' '.(string) $product));
    }

    /** Cache hit by barcode (the exact key), bumping the hit counter — or null on a miss. */
    public static function lookupBarcode(string $barcode): ?self
    {
        $barcode = preg_replace('/\D/', '', $barcode) ?? '';
        if ($barcode === '') {
            return null;
        }
        $row = self::where('barcode', $barcode)->first();
        $row?->increment('hits');

        return $row;
    }

    /** Cache a barcode-scanned product's per-serving macros, keyed on the barcode. */
    public static function rememberBarcode(string $barcode, ?string $brand, ?string $product, array $macros, ?string $source = 'openfoodfacts'): ?self
    {
        $barcode = preg_replace('/\D/', '', $barcode) ?? '';
        if ($barcode === '' || empty($macros['calories'])) {
            return null;
        }

        return self::updateOrCreate(['barcode' => $barcode], [
            'key' => self::keyFor($brand, $product) ?: $barcode,
            'brand' => $brand ? \Illuminate\Support\Str::limit((string) $brand, 120, '') : null,
            'product' => \Illuminate\Support\Str::limit((string) ($product ?: $brand ?: 'Scanned product'), 160, ''),
            'serving' => isset($macros['serving']) ? \Illuminate\Support\Str::limit((string) $macros['serving'], 120, '') : null,
            'calories' => (int) round((float) $macros['calories']),
            'protein_g' => round((float) ($macros['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($macros['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($macros['fat_g'] ?? 0), 1),
            'source' => $source ? \Illuminate\Support\Str::limit($source, 120, '') : null,
        ]);
    }

    /** Cache hit for a product (bumps the hit counter), or null on a miss. */
    public static function lookup(?string $brand, ?string $product): ?self
    {
        $key = self::keyFor($brand, $product);
        if ($key === '') {
            return null;
        }
        $row = self::where('key', $key)->first();
        $row?->increment('hits');

        return $row;
    }

    /**
     * Cache a product's per-serving macros. Upsert on the key so re-reads refresh (a later label photo
     * corrects a coarser web estimate).
     *
     * @param  array{calories:int,protein_g:float,carbs_g:float,fat_g:float,serving?:string}  $macros
     */
    public static function remember(?string $brand, ?string $product, array $macros, ?string $source = null): ?self
    {
        $key = self::keyFor($brand, $product);
        if ($key === '' || empty($macros['calories'])) {
            return null;
        }

        return self::updateOrCreate(['key' => $key], [
            'brand' => $brand ? \Illuminate\Support\Str::limit((string) $brand, 120, '') : null,
            'product' => \Illuminate\Support\Str::limit((string) ($product ?: $brand), 160, ''),
            'serving' => isset($macros['serving']) ? \Illuminate\Support\Str::limit((string) $macros['serving'], 120, '') : null,
            'calories' => (int) round((float) $macros['calories']),
            'protein_g' => round((float) ($macros['protein_g'] ?? 0), 1),
            'carbs_g' => round((float) ($macros['carbs_g'] ?? 0), 1),
            'fat_g' => round((float) ($macros['fat_g'] ?? 0), 1),
            'source' => $source ? \Illuminate\Support\Str::limit($source, 120, '') : null,
        ]);
    }
}
