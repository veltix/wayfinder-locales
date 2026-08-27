<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * `{name}.locale.{locale}` has no reserved namespace: an app that happens to
 * already name a route that exact way would have it silently shadowed —
 * first registered wins, same exposure the pre-existing `.default` twin has.
 * Under `strict` (the default), that should be loud instead.
 */
class LocalizedRouteNameCollisionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
    }

    #[Test]
    public function it_throws_under_strict_mode_when_a_locale_route_name_collides(): void
    {
        config()->set('wayfinder-locales.strict', true);

        Route::get('/manual', fn () => 'manual')->name('products.locale.de');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/products\.locale\.de/');

        Route::get('/{locale?}/products', fn () => 'ok')
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);
    }

    #[Test]
    public function it_silently_shadows_the_collision_under_non_strict_mode(): void
    {
        config()->set('wayfinder-locales.strict', false);

        Route::get('/manual', fn () => 'manual')->name('products.locale.de');

        Route::get('/{locale?}/products', fn () => 'ok')
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->assertSame(
            'manual',
            $this->app['router']->getRoutes()->getByName('products.locale.de')->uri(),
        );
    }
}
