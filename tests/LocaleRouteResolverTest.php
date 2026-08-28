<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

use function Orchestra\Testbench\Pest\defineEnvironment;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

it('matches the uri fallback despite a stale name lookup', function (): void {
    /** @var Router $router */
    $router = $this->app['router'];

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
    expect($metadata->uriForLocale('de'))->toBe('/{locale}/raeumlich');
});
