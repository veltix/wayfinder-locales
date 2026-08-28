<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('app.locale', 'zz');
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->middleware('setlocale')
        ->get('/{locale}/products', fn () => app()->getLocale())
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $router->middleware('setlocale')->get('/plain', fn () => app()->getLocale());
});

it('applies the locale from the route parameter', function (): void {
    $this->get('/de/products')->assertOk()->assertSee('de');
});

it('applies the locale from the route defaults of the unprefixed twin', function (): void {
    $this->get('/products')->assertOk()->assertSee('en');
});

it('ignores locales that are not configured', function (): void {
    $this->get('/fr/products')->assertOk()->assertSee('zz');
});

it('leaves routes without a locale alone', function (): void {
    $this->get('/plain')->assertOk()->assertSee('zz');
});
