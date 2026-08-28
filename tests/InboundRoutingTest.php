<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Assert;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

defineRoutes(function (Router $router): void {
    $router->middleware('setlocale')
        ->get('/{locale?}/products', fn () => app()->getLocale())
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $router->get('/login', fn () => 'login')->name('login');
});

function resolvedUrlFor(string $routeName, string $locale): string
{
    /** @var IlluminateRoute $route */
    $route = app('router')->getRoutes()->getByName($routeName);

    $metadata = app(LocaleRouteResolver::class)->resolveForRangerRoute(
        new RangerRoute($route, new Collection, null, null),
    );

    Assert::assertNotNull($metadata, 'Expected the resolver to produce localized route metadata for ['.$routeName.'].');

    $template = $metadata->uriForLocale($locale);

    Assert::assertNotNull($template, 'Expected a localized URI template for locale ['.$locale.'].');

    $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

    return str_replace(['{'.$parameter.'?}', '{'.$parameter.'}'], $locale, $template);
}

it('matches the translated url and sets the locale', function (): void {
    $this->get('/de/produkte')->assertOk()->assertSee('de');
});

it('matches the url the generator emits for a locale', function (): void {
    $this->get(resolvedUrlFor('products', 'de'))->assertOk()->assertSee('de');
});

it('has lroute generate the same translated url the resolver produces', function (): void {
    expect(lroute('products', [], 'de', absolute: false))->toBe(resolvedUrlFor('products', 'de'));
});

it('has lroute leave a route without a locale parameter unchanged', function (): void {
    expect(lroute('login', [], 'de', absolute: false))->toBe(route('login', [], false));
});
