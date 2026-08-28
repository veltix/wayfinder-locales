<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et']);
    $app['config']->set('wayfinder-locales.default_locale', 'et');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->middleware('setlocale')
        ->get('/product/{slug}', fn (string $slug): string => app()->getLocale().':'.$slug)
        ->name('products.show')
        ->localized(['en' => 'product', 'et' => 'toode']);
});

it('serves the default locale when it is not the language the base URI was written in', function (): void {
    $this->get('/toode/kingad')->assertOk()->assertSee('et:kingad');
});

it('serves the non-default locale at its prefixed twin', function (): void {
    $this->get('/en/product/shoes')->assertOk()->assertSee('en:shoes');
});

it('does not leave the non-default locale reachable at a second, unprefixed URL', function (): void {
    $this->get('/product/shoes')->assertNotFound();
});
