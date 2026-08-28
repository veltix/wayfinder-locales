<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

defineRoutes(function (Router $router): void {
    $router->get('/{locale?}/products', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});

it('does not emit a separate export for each per locale route', function (): void {
    $routes = new Collection([
        rangerRouteNamed('products'),
        rangerRouteNamed('products.locale.en'),
        rangerRouteNamed('products.locale.de'),
    ]);

    $results = $this->app->make(WayfinderRoutes::class)->convert($routes);

    $content = collect($results)->map(fn ($result) => $result->content())->implode(PHP_EOL);

    expect($content)->toContain('productsLocalizedTemplates');
    expect($content)->not->toContain('ProductsLocaleDe');
    expect($content)->not->toContain('ProductsLocaleEn');
    expect($content)->not->toContain('productsLocaleDe');
    expect($content)->not->toContain('productsLocaleEn');
});
