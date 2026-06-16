<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A personal API token. Created with {@see ApiToken::mint()} which returns the one-time plaintext
 * secret; thereafter only `token_hash` is stored. {@see \App\Http\Middleware\AuthenticateApiToken}
 * resolves the bearer back to its owner.
 */
class ApiToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token_hash', 'abilities', 'last_used_at'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a new token for a user. Returns [model, plaintext] — show the plaintext ONCE.
     *
     * @param  array<int,string>  $abilities
     * @return array{0:self,1:string}
     */
    public static function mint(User $user, string $name, array $abilities = ['*']): array
    {
        $plain = 'titan_'.Str::random(40);
        $token = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'abilities' => $abilities ?: ['*'],
        ]);

        return [$token, $plain];
    }

    /** Does this token grant `$ability`? '*' grants everything. */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? ['*'];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
