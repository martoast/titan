<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The user's Titan health profile (the hub of all their data). */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** Get the user's profile, creating an empty one on first access. */
    public function ensureProfile(): Profile
    {
        // Memoize as a loaded relation so the many ensureProfile() calls per request
        // (controllers, layouts, support classes) don't each re-query firstOrCreate.
        if ($this->relationLoaded('profile') && $this->profile) {
            return $this->profile;
        }

        $profile = $this->profile()->firstOrCreate([], [
            'display_name' => $this->name,
        ]);
        $this->setRelation('profile', $profile);

        return $profile;
    }

    /** Personal API tokens (assistant / MCP access). */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** Native-app push tokens (APNs/FCM), registered by the iOS app after login. */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
