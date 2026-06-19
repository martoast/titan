<?php

namespace App\Models;

use App\Support\Biomarkers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bloodwork value at one point in time. The `flag` is derived from the
 * App\Support\Biomarkers catalog -- set it via flagFor() / the booted hook so it
 * always stays in sync with the catalog ranges.
 */
class BiomarkerReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'marker', 'value', 'unit', 'taken_at', 'source', 'flag', 'note',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'taken_at' => 'date',
        ];
    }

    /** Auto-compute the flag from the catalog whenever value/marker change. */
    protected static function booted(): void
    {
        static::saving(function (BiomarkerReading $r) {
            if ($r->flag === null && $r->marker !== null && $r->value !== null) {
                $r->flag = Biomarkers::flag($r->marker, (float) $r->value);
            }
            if ($r->unit === null && $r->marker !== null) {
                $r->unit = Biomarkers::unit($r->marker);
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** The catalog label for this reading's marker. */
    public function getLabelAttribute(): string
    {
        return Biomarkers::label($this->marker);
    }
}
