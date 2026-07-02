<?php

namespace Veltix\WayfinderLocales\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the matched localized route's locale to the application, so
 * translations and `app()->getLocale()` reflect the URL the user is on.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route()?->defaults['locale'] ?? null;

        if (is_string($locale) && in_array($locale, (array) config('wayfinder-i18n.locales', []), true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
