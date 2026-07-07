<?php

namespace Database\Seeders\Concerns;

use App\Models\Profile;

/**
 * Lets a domain seeder hang its sample data off a CHOSEN profile (e.g. a demo / App-Review
 * reviewer account) instead of always profile 1. Set ->seedProfile before calling the seeder;
 * when it's null the old behaviour holds (profile 1, then the first profile), so the normal
 * `db:seed` path is unchanged.
 */
trait SeedsProfile
{
    public ?Profile $seedProfile = null;

    protected function targetProfile(): ?Profile
    {
        return $this->seedProfile ?? Profile::find(1) ?? Profile::orderBy('id')->first();
    }
}
