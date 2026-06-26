<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single taken / skipped / extra dose. The dated log behind adherence and the coach's
 * intake↔biosignal correlations. Name/dose/kind are denormalized off StackItem so one-off
 * doses (no parent) and history outlive edits to the parent item.
 */
class IntakeEvent extends Model
{
    use HasFactory;

    public const STATUSES = ['taken', 'skipped', 'extra'];
    public const SOURCES = ['manual', 'notification', 'coach', 'photo'];

    protected $fillable = [
        'profile_id', 'stack_item_id', 'name', 'kind', 'dose_amount', 'dose_unit',
        'taken_at', 'status', 'source', 'slot', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'dose_amount' => 'decimal:2',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function stackItem(): BelongsTo
    {
        return $this->belongsTo(StackItem::class);
    }
}
