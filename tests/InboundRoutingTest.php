<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

class InboundRoutingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('setlocale')
            ->get('/{locale?}/products', fn () => app()->getLocale())
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);

        $router->get('/login', fn () => 'login')->name('login');
    }

    #[Test]
    public function it_matches_the_translated_url_and_sets_the_locale(): void
    {
        $this->get('/de/produkte')->assertOk()->assertSee('de');
    }

    #[Test]
    public function it_matches_the_url_the_generator_emits_for_a_locale(): void
    {
        $this->get($this->resolvedUrlFor('products', 'de'))->assertOk()->assertSee('de');
    }

    #[Test]
    public function lroute_generates_the_same_translated_url_the_resolver_produces(): void
    {
        $this->assertSame(
            $this->resolvedUrlFor('products', 'de'),
            lroute('products', [], 'de', absolute: false),
        );
    }

    #[Test]
    public function lroute_leaves_a_route_without_a_locale_parameter_unchanged(): void
    {
        $this->assertSame(
            route('login', [], false),
            lroute('login', [], 'de', absolute: false),
        );
    }

    private function resolvedUrlFor(string $routeName, string $locale): string
    {
        /** @var IlluminateRoute $route */
        $route = $this->app['router']->getRoutes()->getByName($routeName);

        $metadata = $this->app->make(LocaleRouteResolver::class)->resolveForRangerRoute(
            new RangerRoute($route, new Collection, null, null),
        );

        $this->assertNotNull($metadata, 'Expected the resolver to produce localized route metadata for ['.$routeName.'].');

        $template = $metadata->uriForLocale($locale);

        $this->assertNotNull($template, 'Expected a localized URI template for locale ['.$locale.'].');

        $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

        return str_replace(['{'.$parameter.'?}', '{'.$parameter.'}'], $locale, $template);
    }
}
