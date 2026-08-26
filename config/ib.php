<?php

// config/ib.php in the REPORTING project.
return [
    // The hub's network alias. NOT a bare name like `app` — half the projects
    // on this box have a service called that, and a bare name resolves to
    // whoever answers first.
    'url' => env('IB_URL', 'http://ib-hub'),
    'key' => env('IB_KEY'),
    'release' => env('IB_RELEASE', env('APP_RELEASE')),
];
