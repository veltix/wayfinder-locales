<?php

use Illuminate\Contracts\Routing\UrlRoutable;
use Veltix\WayfinderLocales\Locale\DefaultLocaleResolver;

if (! function_exists('lroute')) {
    /**
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

        $defaultLocale = app(DefaultLocaleResolver::class)->resolve();

        if ($defaultLocale !== null && $locale === $defaultLocale) {
            $defaultName = $name.'.default';

            if ($routes->getByName($defaultName) !== null) {
                return app('url')->route($defaultName, $parameters, $absolute);
            }
        }

        $route = $routes->getByName($name);

        if ($route !== null && in_array($parameter, $route->parameterNames(), true)) {
            $parameters = is_array($parameters) ? $parameters : [$parameters];
            $parameters[$parameter] ??= $locale;
        }

        return app('url')->route($name, $parameters, $absolute);
    }
}
