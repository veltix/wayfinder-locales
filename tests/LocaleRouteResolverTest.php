<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

/**
 * `LocaleRouteResolver::findIlluminateRoute()` locates the Illuminate route
 * behind a `RangerRoute` wrapper two ways: by name, or — when the name
 * lookup table has not been refreshed yet — by comparing URIs. The second
 * path was unconditionally dead: `Laravel\Ranger\Components\Route::uri()`
 * always carries a leading slash, the native `Illuminate\Routing\Route::uri()`
 * never does, so the comparison could never match and the fallback silently
 * returned null instead of the route.
 */
class LocaleRouteResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
    }

    #[Test]
    public function the_uri_fallback_matches_despite_a_stale_name_lookup(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        // Registered after the app finished booting, so — unlike routes
        // declared through `defineRoutes()` — nothing has refreshed the name
        // lookup table for it. This reproduces the exact staleness the
        // resolver's name-based lookup can hit.
        $route = $router->addRoute(['GET'], '{locale}/raeumlich', fn () => 'ok');
        $route->name('spatial');

        $action = $route->getAction();
        $action[(string) config('wayfinder-locales.action_key', 'wayfinder_locales')] = [
            'translations' => ['en' => 'spatial', 'de' => 'raeumlich'],
        ];
        $route->setAction($action);

        $this->assertNull(
            $router->getRoutes()->getByName('spatial'),
            'Sanity check: the name lookup for a route named after boot is expected to be stale.',
        );

        $metadata = $this->app->make(LocaleRouteResolver::class)->resolveForRangerRoute(
            new RangerRoute($route, new Collection, null, null),
        );

        $this->assertNotNull($metadata, 'Expected the URI-based fallback to find the route despite the stale name lookup.');
        $this->assertSame('/{locale}/raeumlich', $metadata->uriForLocale('de'));
    }
}
