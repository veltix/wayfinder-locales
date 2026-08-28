<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->get('/order/{order}', fn (string $order): string => 'order:'.$order)
        ->whereUuid('order')
        ->name('orders.show')
        ->localized(['en' => 'order', 'et' => 'tellimus']);

    $router->get('/page/{page}', fn (string $page): string => 'page:'.$page)
        ->whereNumber('page')
        ->name('pages.show')
        ->localized(['en' => 'page', 'et' => 'leht']);
});

it('applies a uuid constraint on the base route', function (): void {
    $this->get('/order/not-a-uuid')->assertNotFound();
});

it('applies the same uuid constraint on the localized twin', function (): void {
    $this->get('/et/tellimus/not-a-uuid')->assertNotFound();
});

it('still matches a valid uuid on the localized twin', function (): void {
    $uuid = '8ad97db7-42a7-4234-be37-1ed3fbddc537';

    $this->get('/et/tellimus/'.$uuid)->assertOk()->assertSee('order:'.$uuid);
});

it('applies a numeric constraint on the localized twin', function (): void {
    $this->get('/et/leht/abc')->assertNotFound();
    $this->get('/et/leht/12')->assertOk()->assertSee('page:12');
});
