<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HydrationLog extends Model
{
    use HasFactory;

    protected $fillable = ['profile_id', 'logged_on', 'amount_ml', 'source'];

    protected function casts(): array
    {
        return ['logged_on' => 'date', 'amount_ml' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
