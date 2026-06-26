<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Web Push (PWA notifications) — VAPID keypair for signed push to the browser.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:hello@titan.app'),
    ],

    // Polar AccessLink — free official wearable API (OAuth2 + webhooks). A real
    // second data source. Get dev credentials at https://admin.polaraccesslink.com.
    'polar' => [
        'client_id' => env('POLAR_CLIENT_ID'),
        'client_secret' => env('POLAR_CLIENT_SECRET'),
        'redirect' => env('POLAR_REDIRECT_URI', env('APP_URL').'/devices/polar/callback'),
        'base_url' => env('POLAR_BASE_URL', 'https://www.polaraccesslink.com'),
    ],

    // Terra — unified wearable API (https://tryterra.co). One integration covers
    // Whoop, Oura, Garmin, Apple Health, etc. Connect flow = the Terra widget;
    // data arrives via signed webhooks. Get these from the Terra dashboard.
    'terra' => [
        'dev_id' => env('TERRA_DEV_ID'),
        'api_key' => env('TERRA_API_KEY'),
        'signing_secret' => env('TERRA_SIGNING_SECRET'),
        'base_url' => env('TERRA_BASE_URL', 'https://api.tryterra.co/v2'),
    ],

    // Biosignal — the self-hosted FastAPI service (NeuroKit2 HRV, Walch sleep, TRIMP
    // activity). Internal-only on the docker network; Laravel reaches it at
    // http://biosignal:8000 and authenticates with a shared bearer token. Stateless:
    // it gets a window of raw signal and returns metrics; it never touches the DB.
    'biosignal' => [
        'url' => env('BIOSIGNAL_URL', 'http://biosignal:8000'),
        'token' => env('BIOSIGNAL_TOKEN'),
        'algo_version' => env('BIOSIGNAL_ALGO_VERSION', 'v1'),
        'timeout' => (int) env('BIOSIGNAL_TIMEOUT', 60),
    ],

    // Mapbox — run route maps (Strava-style). The public token (pk.*) renders the
    // interactive Mapbox GL map in-app and the Static Images API `path(polyline)`
    // share-card PNG. It's a publishable client token (URL/scope-restricted), but we
    // still keep it in .env and expose it only to views that draw a map.
    'mapbox' => [
        'token' => env('MAPBOX_API_TOKEN'),
    ],

    // Mailgun — transactional email (password resets + notifications). The active
    // mailer is SMTP (MAIL_MAILER=smtp, Mailgun's SMTP relay); these credentials
    // also enable the `mailgun` API transport. Same account as fullstack-suite.
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    // OpenAI — the Titan coach's brain: conversation (chat_model), fast/cheap
    // turns (fast_model), vision for meal + progress photos (vision_model), and
    // embeddings for semantic search over each profile's health wiki.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
        'fast_model' => env('OPENAI_FAST_MODEL', 'gpt-4o-mini'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
        'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
        'transcribe_model' => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
        // gpt-image-1 — used for the dream physique (real photo → fitter you). It preserves the
        // person's face with input_fidelity=high and handles self-transformations Gemini refuses.
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'image_timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 180),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
    ],

    // Which provider renders the dream physique / living-goal morph (real-person edits):
    //   'openai' → gpt-image-1 (keeps the face, permits self-transformations)
    //   'gemini' → Nano Banana (cheaper, but refuses real-person muscle edits on TOS grounds)
    // Meal-photo generation always stays on Gemini (it's not a real person).
    'image' => [
        'provider' => env('PHYSIQUE_IMAGE_PROVIDER', 'openai'),
    ],

    // Google Gemini — "Nano Banana 2" (Gemini Flash Image) for image generation:
    // the dream-physique goal image (current photo + muscle) and the periodic
    // progress-morph renders. Supports image+text → image input.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image-preview'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
    ],

    // Live web access for grounding the coach in real data (search + scrape).
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
    ],
    'scraperapi' => [
        'key' => env('SCRAPERAPI_KEY'),
    ],

];
