<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et', 'ru']);
    $app['config']->set('wayfinder-locales.default_locale', 'et');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->get('/account/profile', fn (): string => 'profile')
        ->name('account.profile')
        ->localized(['en' => 'account/profile', 'et' => 'konto/profiil', 'ru' => 'akkaunt/profil']);

    $router->get('/orders/{order:public_id}/view', fn (string $order): string => 'view:'.$order)
        ->name('orders.view')
        ->localized(['en' => 'orders/{order}/view', 'et' => 'tellimused/{order}/vaata', 'ru' => 'zakazy/{order}/prosmotr']);

    $router->get('/account/addresses', fn (): string => 'addresses')
        ->name('account.addresses')
        ->localized(['en' => 'account', 'et' => 'konto', 'ru' => 'akkaunt']);
});

it('translates every segment a multi-segment translation names', function (): void {
    expect(lroute('account.profile', [], 'et', false))->toBe('/konto/profiil')
        ->and(lroute('account.profile', [], 'ru', false))->toBe('/ru/akkaunt/profil')
        ->and(lroute('account.profile', [], 'en', false))->toBe('/en/account/profile');

    $this->get('/ru/akkaunt/profil')->assertOk()->assertSee('profile');
    $this->get('/konto/profiil')->assertOk();
    $this->get('/ru/akkaunt/profile')->assertNotFound();
});

it('keeps the parameters and their binding fields where the translation puts them', function (): void {
    expect(lroute('orders.view', ['order' => 'abc'], 'ru', false))->toBe('/ru/zakazy/abc/prosmotr')
        ->and(lroute('orders.view', ['order' => 'abc'], 'et', false))->toBe('/tellimused/abc/vaata');

    $this->get('/ru/zakazy/abc/prosmotr')->assertOk()->assertSee('view:abc');

    foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'orders.view')) {
            expect($route->bindingFields())->toBe(['order' => 'public_id'], (string) $route->getName());
        }
    }
});

it('still replaces only the first static segment for a one-segment translation', function (): void {
    expect(lroute('account.addresses', [], 'ru', false))->toBe('/ru/akkaunt/addresses');
});
