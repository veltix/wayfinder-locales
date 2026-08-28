<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use PHPUnit\Framework\Attributes\Test;

class SetLocaleMiddlewareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.locale', 'zz');
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
        $app['config']->set('wayfinder-locales.hide_default_prefix', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('setlocale')
            ->get('/{locale}/products', fn () => app()->getLocale())
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);

        $router->middleware('setlocale')->get('/plain', fn () => app()->getLocale());
    }

    #[Test]
    public function it_applies_the_locale_from_the_route_parameter(): void
    {
        $this->get('/de/products')->assertOk()->assertSee('de');
    }

    #[Test]
    public function it_applies_the_locale_from_the_route_defaults_of_the_unprefixed_twin(): void
    {
        $this->get('/products')->assertOk()->assertSee('en');
    }

    #[Test]
    public function it_ignores_locales_that_are_not_configured(): void
    {
        $this->get('/fr/products')->assertOk()->assertSee('zz');
    }

    #[Test]
    public function it_leaves_routes_without_a_locale_alone(): void
    {
        $this->get('/plain')->assertOk()->assertSee('zz');
    }
}
