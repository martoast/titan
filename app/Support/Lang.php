<?php

namespace App\Support;

/**
 * Maps a profile's user-facing language choice (profile.primary_language, e.g. 'en' | 'es-MX') to the
 * Laravel translator locale used for emails, push copy and server-composed prose.
 *
 * IMPORTANT: we deliberately collapse the region ('es-MX' → 'es'). Laravel's plural-rule table
 * (MessageSelector::getPluralIndex) keys on the *bare* language and does not recognise region tags —
 * a locale of 'es-MX' silently falls through to the default rule and always picks the singular form
 * ("3 sesión"). Using 'es' gives correct Spanish pluralisation while the app keeps 'es-MX' as the
 * user's choice. All Spanish here is Mexican Spanish; lang/es.json holds the es-MX copy.
 */
class Lang
{
    public static function locale(?string $primaryLanguage): string
    {
        return str_starts_with((string) $primaryLanguage, 'es') ? 'es' : config('app.locale', 'en');
    }
}
