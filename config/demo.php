<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App Review demo account
    |--------------------------------------------------------------------------
    |
    | The email of the demo account whose data is refreshed nightly by
    | the scheduler (see routes/console.php). `titan:seed-demo` dates every
    | night, meal and recovery score relative to the moment it runs, so the
    | account decays into an empty "No data" home screen within a day or two
    | of being seeded — which is exactly what an app reviewer then sees.
    |
    | Leave this unset (the default) and nothing is scheduled: a self-hosted
    | Titan never grows a reviewer account it did not ask for. Set it only on
    | the instance whose credentials are in App Store Connect.
    |
    */

    'reviewer_email' => env('DEMO_REVIEWER_EMAIL'),

];
