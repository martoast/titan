<?php

namespace App\Services\Glucose;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * A source of continuous-glucose readings (CGM_INTEGRATION P1). Sources are swappable — Nightscout,
 * HealthKit, (later) Dexcom — so every surface reads normalized readings and never cares where they came
 * from. Each returned reading is a normalized array: {taken_at:Carbon(UTC), mg_dl:int, trend:?string,
 * device:?string, raw:array}.
 */
interface GlucoseProvider
{
    /** @return array<int,array{taken_at:Carbon,mg_dl:int,trend:?string,device:?string,raw:array}> */
    public function fetchSince(Profile $profile, Carbon $since): array;
}
