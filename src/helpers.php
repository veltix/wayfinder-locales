<?php

use Illuminate\Contracts\Routing\UrlRoutable;

if (! function_exists('lroute')) {
    /**
     * Generate a URL for a localized route in a specific locale (defaults to the
     * active locale), falling back to the unprefixed route when no localized
     * variant exists. Use this when you need an explicit locale (sitemaps, a
     * language switcher); for the active locale, plain `route()` already works
     * when the locale-aware UrlGenerator is enabled.
     *
     * @param  array<string, mixed>|string|UrlRoutable  $parameters
     */
    function lroute(string $name, $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();
        $localizedName = $locale.'.'.$name;

        $routes = app('router')->getRoutes();

        $resolved = $routes->hasNamedRoute($localizedName) ? $localizedName : $name;

        return app('url')->route($resolved, $parameters, $absolute);
    }
}
