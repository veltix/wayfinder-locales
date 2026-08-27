<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;
use PHPUnit\Framework\Attributes\Test;

/**
 * `Route::localized()` registers a concrete twin route per locale
 * (`products.locale.de`) purely for inbound matching. Wayfinder's own route
 * converter has no idea those are plumbing: left unfiltered, it would emit a
 * separate named export for each one — growing with the locale count — on
 * top of the combined `products.url({ locale })` the base route already
 * produces.
 */
class LocaleAwareRouteTransformerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/{locale?}/products', fn () => 'ok')
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);
    }

    #[Test]
    public function it_does_not_emit_a_separate_export_for_each_per_locale_route(): void
    {
        $routes = new Collection([
            $this->rangerRoute('products'),
            $this->rangerRoute('products.locale.en'),
            $this->rangerRoute('products.locale.de'),
        ]);

        $results = $this->app->make(WayfinderRoutes::class)->convert($routes);

        $content = collect($results)->map(fn ($result) => $result->content())->implode(PHP_EOL);

        $this->assertStringContainsString('productsLocalizedTemplates', $content);
        $this->assertStringNotContainsString('ProductsLocaleDe', $content);
        $this->assertStringNotContainsString('ProductsLocaleEn', $content);
        $this->assertStringNotContainsString('productsLocaleDe', $content);
        $this->assertStringNotContainsString('productsLocaleEn', $content);
    }

    private function rangerRoute(string $name): RangerRoute
    {
        /** @var IlluminateRoute $route */
        $route = $this->app['router']->getRoutes()->getByName($name);

        return new RangerRoute($route, new Collection, null, null);
    }
}
