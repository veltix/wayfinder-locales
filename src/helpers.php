<?php

use Illuminate\Contracts\Routing\UrlRoutable;

if (! function_exists('lroute')) {
    /**
     * Generate a URL for a localized route in a specific locale (defaults to the
     * active locale).
     *
     * Localized routes carry the locale as a URI parameter, so this is `route()`
     * with that parameter filled in for you. Routes without a locale parameter
     * are generated unchanged, which keeps unprefixed routes (Fortify's `login`,
     * say) working through the same call.
     *
     * @param  array<string, mixed>|string|UrlRoutable  $parameters
     */
    function lroute(string $name, $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();
        $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

        $route = app('router')->getRoutes()->getByName($name);

        if ($route !== null && in_array($parameter, $route->parameterNames(), true)) {
            $parameters = is_array($parameters) ? $parameters : [$parameters];
            $parameters[$parameter] ??= $locale;
        }

        return app('url')->route($name, $parameters, $absolute);
    }
}
