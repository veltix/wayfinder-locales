<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Tests\Concerns\WritesLangFiles;

/**
 * One config key, `wayfinder-locales.default_locale`, and both halves of the
 * package obey it. Before the merge each half read its own file — under
 * dev-next only one of those files was even loaded.
 */
class DefaultLocaleTest extends TestCase
{
    use WritesLangFiles;

    protected function setUp(): void
    {
        $this->setUpWorkspace();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkspace();

        parent::tearDown();
    }

    protected function langFiles(): array
    {
        return [
            'en/messages.php' => ['only_en' => 'English'],
            'de/messages.php' => ['only_de' => 'Deutsch'],
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->useLangPath($this->workspace.'/lang');
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'de');
        $app['config']->set('wayfinder-locales.hide_default_prefix', true);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('setlocale')
            ->get('/{locale}/products', fn () => app()->getLocale())
            ->name('products')
            ->localized(['en' => 'products', 'de' => 'produkte']);
    }

    /**
     * The default locale's catalog seeds the whole key union; every other
     * locale is looked up against it.
     */
    #[Test]
    public function the_default_locale_seeds_the_translation_key_union(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $keys = $this->generated('translations/keys.ts');

        $this->assertStringContainsString('messages.only_de', $keys);
        $this->assertStringNotContainsString('messages.only_en', $keys);
    }

    #[Test]
    public function the_default_locale_is_emitted_as_the_runtime_fallback(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "export const defaultLocale: Locale = 'de';",
            $this->generated('translations/locales.ts'),
        );
    }

    /**
     * The same key drives route generation: the default locale is the one whose
     * URL prefix `hide_default_prefix` drops.
     */
    #[Test]
    public function the_default_locale_drives_route_url_generation(): void
    {
        $uris = $this->resolveLocalizedUris('products');

        $this->assertSame('/produkte', $uris['de']);
        $this->assertSame('/{locale}/products', $uris['en']);
    }

    /**
     * The bug this guards: the twin used to be built from the declared URI
     * literal with the `{locale}` placeholder stripped, so it never consulted
     * the translation map. With `default_locale => 'de'` and
     * `['en' => 'products', 'de' => 'produkte']`, that served German at
     * `/products` instead of `/produkte`.
     */
    #[Test]
    public function the_default_locale_drives_the_unprefixed_route_registration(): void
    {
        $default = $this->app['router']->getRoutes()->getByName('products.default');

        $this->assertNotNull($default);
        $this->assertSame('produkte', $default->uri());
        $this->assertSame('de', $default->defaults['locale']);
    }

    /**
     * The round trip: the unprefixed twin must actually serve the default
     * locale's translated segment...
     */
    #[Test]
    public function the_unprefixed_route_resolves_at_the_default_locales_translated_segment(): void
    {
        $this->get('/produkte')->assertOk()->assertSee('de');
    }

    /**
     * ...and the old literal — what the twin used to be registered under —
     * must stop resolving once the twin moves. Without this assertion, the
     * test above would still pass if the twin were (incorrectly) registered
     * under both `/produkte` and `/products`, which would leave a
     * duplicate-content URL live on the site.
     */
    #[Test]
    public function the_old_literal_no_longer_resolves_once_the_twin_moves(): void
    {
        $this->get('/products')->assertNotFound();
    }

    /**
     * The common case, and the one the consuming app actually runs in
     * production: when the default locale's segment happens to equal the
     * declared literal, the fix must not move it. Registered here with a
     * distinct route name against a temporarily-swapped default_locale, since
     * the class-level fixture's default is 'de'.
     */
    #[Test]
    public function the_unprefixed_route_is_unmoved_when_the_default_segment_equals_the_literal(): void
    {
        config()->set('wayfinder-locales.default_locale', 'en');

        $this->app['router']->get('/{locale}/products', fn () => 'ok')
            ->name('products.english_default')
            ->localized(['en' => 'products', 'de' => 'produkte']);

        // Routes named after being added to the collection (the twin is
        // named this way, same as the route above it) only surface through
        // `getByName()` once the name look-up table is rebuilt — Testbench
        // does this itself after `defineRoutes()`, but a route registered
        // mid-test has to trigger it explicitly.
        $this->app['router']->getRoutes()->refreshNameLookups();

        $default = $this->app['router']->getRoutes()->getByName('products.english_default.default');

        $this->assertNotNull($default);
        $this->assertSame('products', $default->uri());
        $this->assertSame('en', $default->defaults['locale']);
    }

    #[Test]
    public function it_warns_when_the_default_locale_is_not_in_the_configured_list(): void
    {
        config()->set('wayfinder-locales.default_locale', 'fr');

        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->expectsOutputToContain('is not listed in wayfinder-locales.locales')
            ->assertSuccessful();
    }

    /**
     * @return array<string, string>
     */
    private function resolveLocalizedUris(string $name): array
    {
        /** @var IlluminateRoute $route */
        $route = $this->app['router']->getRoutes()->getByName($name);

        $metadata = $this->app->make(LocaleRouteResolver::class)->resolveForRangerRoute(
            new RangerRoute($route, new Collection, null, null),
        );

        $this->assertNotNull($metadata);

        return $metadata->localizedUris;
    }
}
