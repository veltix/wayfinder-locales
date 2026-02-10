<?php

declare(strict_types=1);

return [
    'enabled' => env('WAYFINDER_LOCALES_ENABLED', true),

    // segment: localized value replaces static slug segment
    // tail: localized value is treated as full localized path tail
    'mode' => env('WAYFINDER_LOCALES_MODE', 'segment'),

    // Internal route action key used by Route::localized([...])
    'action_key' => 'wayfinder_locales',

    'locale_parameter' => env('WAYFINDER_LOCALE_PARAMETER', 'locale'),

    // Used when route locale is optional and not provided
    'default_locale' => env('WAYFINDER_DEFAULT_LOCALE', null),

    // Throw on invalid metadata during generation
    'strict' => env('WAYFINDER_LOCALES_STRICT', true),
];
