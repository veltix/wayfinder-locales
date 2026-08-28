<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route !== null) {
            $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

            $locale = $route->parameter($parameter) ?? ($route->defaults[$parameter] ?? null);

            if (is_string($locale) && in_array($locale, (array) config('wayfinder-locales.locales', []), true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
