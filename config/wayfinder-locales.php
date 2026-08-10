<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | Every locale the package generates for. This is the single canonical list:
    | it drives the generated `Locale` union, the per-locale translation catalog
    | modules, and the locales the `setlocale` middleware will accept.
    |
    */

    'locales' => ['en'],

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | The default locale's catalog is the source of truth for the generated
    | `TranslationKey` union and the runtime's fallback when a key is missing
    | from the active locale. It is also the locale whose prefix is dropped when
    | `hide_default_prefix` is enabled. It should appear in `locales`.
    |
    */

    'default_locale' => env('WAYFINDER_DEFAULT_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Localized Route Generation
    |--------------------------------------------------------------------------
    |
    | `enabled` turns off localized URL template emission without unwinding the
    | `Route::localized()` calls. `mode` chooses how a translation value is
    | applied: "segment" replaces the first static slug segment after the locale,
    | "tail" treats the value as the whole localized path tail. `strict` throws
    | on malformed metadata instead of silently skipping the route.
    |
    */

    'enabled' => env('WAYFINDER_LOCALES_ENABLED', true),

    'mode' => env('WAYFINDER_LOCALES_MODE', 'segment'),

    'strict' => env('WAYFINDER_LOCALES_STRICT', true),

    /*
    |--------------------------------------------------------------------------
    | Locale Route Parameter
    |--------------------------------------------------------------------------
    |
    | The URI parameter carrying the locale, e.g. `/{locale}/products`. Read by
    | route generation, the `setlocale` middleware, and `lroute()`.
    |
    */

    'locale_parameter' => env('WAYFINDER_LOCALE_PARAMETER', 'locale'),

    /*
    |--------------------------------------------------------------------------
    | Hide Default Locale Prefix
    |--------------------------------------------------------------------------
    |
    | When true, `Route::localized()` also registers an unprefixed twin of the
    | route (named `{name}.default`) bound to `default_locale`, and generation
    | emits the unprefixed URL for that locale — "/products" rather than
    | "/en/products".
    |
    */

    'hide_default_prefix' => env('WAYFINDER_HIDE_DEFAULT_PREFIX', false),

    /*
    |--------------------------------------------------------------------------
    | Translation Catalog Exclusions
    |--------------------------------------------------------------------------
    |
    | PHP lang groups (file basenames) kept out of the generated frontend
    | catalogs. `routes` is excluded by default: apps upgrading from v2 kept
    | their translated URL segments there, and those are not UI strings.
    |
    */

    'exclude_groups' => ['routes'],

    /*
    |--------------------------------------------------------------------------
    | Route Action Key
    |--------------------------------------------------------------------------
    |
    | The route action array key under which `Route::localized()` stashes its
    | per-locale translation map. Change it only on a collision.
    |
    */

    'action_key' => 'wayfinder_locales',

];
