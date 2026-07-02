<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | The locales that localized routes and translation catalogs are generated
    | for. The first/default locale is the "source of truth" for the generated
    | TypeScript `Locale` union and translation keys.
    |
    */

    'locales' => ['en'],

    'default' => env('WAYFINDER_I18N_DEFAULT_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Hide Default Locale Prefix
    |--------------------------------------------------------------------------
    |
    | When true, the default locale's routes are registered without a locale
    | prefix (e.g. "/search" instead of "/en/search"). Other locales are always
    | prefixed (e.g. "/de/suche").
    |
    */

    'hide_default_prefix' => env('WAYFINDER_I18N_HIDE_DEFAULT_PREFIX', true),

    /*
    |--------------------------------------------------------------------------
    | Route Segment Translation File
    |--------------------------------------------------------------------------
    |
    | The lang file (lang/{locale}/{lang_file}.php) holding the translated URL
    | segments, keyed by segment: ['search' => 'suche', 'listings' => 'anzeigen'].
    | This group is excluded from the generated frontend translation catalogs.
    |
    */

    'lang_file' => 'routes',

    /*
    |--------------------------------------------------------------------------
    | Locale-aware route() URLs
    |--------------------------------------------------------------------------
    |
    | When true, the `url` binding is swapped for a generator that resolves
    | `route('name')` to the active locale's `{locale}.name` variant when it
    | exists (falling back to the plain name). Lets existing route() calls work
    | unchanged. Disable to manage locale URLs manually via lroute().
    |
    */

    'locale_aware_urls' => env('WAYFINDER_I18N_LOCALE_AWARE_URLS', true),

    /*
    |--------------------------------------------------------------------------
    | Translation Catalog Exclusions
    |--------------------------------------------------------------------------
    |
    | PHP lang groups (file basenames) to exclude from the generated frontend
    | translation catalogs. The route segment file is always excluded.
    |
    */

    'exclude_groups' => [],

];
