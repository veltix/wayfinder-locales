<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the matched route's locale to the application, so translations and
 * `app()->getLocale()` reflect the URL the user is on.
 *
 * Localized routes carry the locale as a URI parameter (`/{locale}/products`).
 * When `hide_default_prefix` is on, the generated unprefixed twin carries it as
 * a route default instead, so both are consulted.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route !== null) {
            $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

            $locale = $route->parameter($parameter) ?? ($route->defaults[$parameter] ?? null);

            if (is_string($locale) && in_array($locale, (array) config('wayfinder-i18n.locales', []), true)) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
