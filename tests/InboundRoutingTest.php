<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

/**
 * Gap 1: `LocaleRouteResolver` computes the translated URL Wayfinder emits to
 * the client — `products.url({locale: 'de'})` becomes `/de/produkte` — but
 * nothing registers an inbound route that matches it. `SetLocale` only reads
 * the locale off whatever route already matched the request; it never
 * rewrites the incoming path. So the URL the package tells the client to
 * visit 404s.
 *
 * `SetLocaleMiddlewareTest` never caught this: it only ever requests the
 * untranslated `{locale}` URI Laravel already has registered
 * (`/de/products`), so it has never compared that against the URI the
 * package actually emits (`/de/produkte`).
 */
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
    }

    #[Test]
    public function it_matches_the_translated_url_and_sets_the_locale(): void
    {
        $this->get('/de/produkte')->assertOk()->assertSee('de');
    }

    /**
     * Same property, asserted from the generator's side instead of a literal
     * path. `resolvedUrlFor()` runs the exact computation
     * `LocaleAwareRouteTransformer` uses to emit the client's
     * `products.url({locale: 'de'})`, so if the translation map, the mode, or
     * the segment ever change, this assertion follows them instead of
     * silently testing a stale path.
     */
    #[Test]
    public function it_matches_the_url_the_generator_emits_for_a_locale(): void
    {
        $this->get($this->resolvedUrlFor('products', 'de'))->assertOk()->assertSee('de');
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
