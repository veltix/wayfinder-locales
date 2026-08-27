<?php

use Illuminate\Contracts\Routing\UrlRoutable;

if (! function_exists('lroute')) {
    /**
     * Generate a URL for a localized route in a specific locale (defaults to the
     * active locale).
     *
     * `Route::localized()` registers a concrete twin route per locale, named
     * `{name}.locale.{locale}`, carrying that locale's translated segment. When
     * one exists for the requested locale, this routes through it directly, so
     * the URL returned is the translated one (`/de/produkte`) rather than the
     * base route's own URI with the locale parameter filled in (`/de/products`).
     *
     * Routes without a locale parameter (Fortify's `login`, say) never get a
     * twin, so they fall through to `route()` unchanged — that fallback is
     * unaffected by the above and consumers rely on it.
     *
     * @param  array<string, mixed>|string|UrlRoutable  $parameters
     */
    function lroute(string $name, $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();
        $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

        $routes = app('router')->getRoutes();
        $localizedName = $name.'.locale.'.$locale;

        if ($routes->getByName($localizedName) !== null) {
            return app('url')->route($localizedName, $parameters, $absolute);
        }

        $route = $routes->getByName($name);

        if ($route !== null && in_array($parameter, $route->parameterNames(), true)) {
            $parameters = is_array($parameters) ? $parameters : [$parameters];
            $parameters[$parameter] ??= $locale;
        }

        return app('url')->route($name, $parameters, $absolute);
    }
}
