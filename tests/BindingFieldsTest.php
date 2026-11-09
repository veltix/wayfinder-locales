<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et', 'ru']);
    $app['config']->set('wayfinder-locales.default_locale', 'et');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->post('/products/{product:slug}/reviews', fn (string $product): string => 'reviews:'.$product)
        ->name('reviews.store')
        ->localized(['en' => 'products', 'et' => 'tooted', 'ru' => 'produkty']);

    $router->get('/{locale}/orders/{order:public_id}', fn (string $order): string => 'order:'.$order)
        ->name('orders.show')
        ->localized(['en' => 'orders', 'et' => 'tellimused', 'ru' => 'zakazy']);
});

/**
 * @return array<string, Route>
 */
function routesNamedLike(string $name): array
{
    $routes = [];

    foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
        $routeName = $route->getName();

        if ($routeName !== null && str_starts_with($routeName, $name)) {
            $routes[$routeName] = $route;
        }
    }

    return $routes;
}

it('keeps the binding field when the default locale rewrites a placeholder-free uri', function (): void {
    $routes = routesNamedLike('reviews.store');

    expect($routes)->toHaveKeys(['reviews.store', 'reviews.store.locale.en', 'reviews.store.locale.ru'])
        ->and($routes['reviews.store']->uri())->toBe('tooted/{product}/reviews');

    foreach ($routes as $routeName => $route) {
        expect($route->bindingFields())->toBe(['product' => 'slug'], $routeName);
    }
});

it('keeps the binding field on the hidden-prefix default twin and every locale twin', function (): void {
    $routes = routesNamedLike('orders.show');

    expect($routes)->toHaveKeys(['orders.show', 'orders.show.locale.en', 'orders.show.locale.ru']);

    foreach ($routes as $routeName => $route) {
        expect($route->bindingFields())->toBe(['order' => 'public_id'], $routeName);
    }
});
