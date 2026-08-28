<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Tests\Concerns\WritesLangFiles;

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

    #[Test]
    public function the_default_locale_drives_route_url_generation(): void
    {
        $uris = $this->resolveLocalizedUris('products');

        $this->assertSame('/produkte', $uris['de']);
        $this->assertSame('/{locale}/products', $uris['en']);
    }

    #[Test]
    public function the_default_locale_drives_the_unprefixed_route_registration(): void
    {
        $default = $this->app['router']->getRoutes()->getByName('products.default');

        $this->assertNotNull($default);
        $this->assertSame('produkte', $default->uri());
        $this->assertSame('de', $default->defaults['locale']);
    }

    #[Test]
    public function the_unprefixed_route_resolves_at_the_default_locales_translated_segment(): void
    {
        $this->get('/produkte')->assertOk()->assertSee('de');
    }

    #[Test]
    public function the_old_literal_no_longer_resolves_once_the_twin_moves(): void
    {
        $this->get('/products')->assertNotFound();
    }

    #[Test]
    public function the_unprefixed_route_is_unmoved_when_the_default_segment_equals_the_literal(): void
    {
        config()->set('wayfinder-locales.default_locale', 'en');

        $this->app['router']->get('/{locale}/products', fn () => 'ok')
            ->name('products.english_default')
            ->localized(['en' => 'products', 'de' => 'produkte']);

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

    #[Test]
    public function a_closure_default_locale_drives_the_unprefixed_route_registration(): void
    {
        config()->set('wayfinder-locales.default_locale', fn (): string => 'de');

        $this->app['router']->get('/{locale}/gadgets', fn () => 'ok')
            ->name('gadgets')
            ->localized(['en' => 'gadgets', 'de' => 'apparate']);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $default = $this->app['router']->getRoutes()->getByName('gadgets.default');

        $this->assertNotNull($default);
        $this->assertSame('apparate', $default->uri());
        $this->assertSame('de', $default->defaults['locale']);
    }

    #[Test]
    public function the_resolver_closure_is_invoked_once_per_registration_pass_not_once_per_route(): void
    {
        $calls = 0;

        config()->set('wayfinder-locales.default_locale', function () use (&$calls): string {
            $calls++;

            return 'de';
        });

        foreach (['widgets', 'sprockets', 'gizmos'] as $name) {
            $this->app['router']->get("/{locale}/{$name}", fn () => 'ok')
                ->name($name)
                ->localized(['en' => $name, 'de' => $name.'-de']);
        }

        $this->assertSame(
            1,
            $calls,
            'Expected the default_locale closure to be invoked once for 3 routes, not once per route.',
        );
    }

    #[Test]
    public function a_throwing_resolver_falls_back_and_registration_still_completes(): void
    {
        config()->set('wayfinder-locales.default_locale', function (): string {
            throw new RuntimeException('settings store unavailable');
        });

        $this->app['router']->get('/{locale}/thingamajigs', fn () => 'ok')
            ->name('thingamajigs')
            ->localized(['en' => 'thingamajigs', 'de' => 'dinger']);

        $this->app['router']->getRoutes()->refreshNameLookups();

        $localized = $this->app['router']->getRoutes()->getByName('thingamajigs');
        $this->assertNotNull($localized, 'Route registration must complete even when the resolver throws.');

        $default = $this->app['router']->getRoutes()->getByName('thingamajigs.default');

        $this->assertNotNull($default, 'Expected the unprefixed twin to still be registered off the fallback locale.');
        $this->assertSame('thingamajigs', $default->uri());
        $this->assertSame('en', $default->defaults['locale'], 'Expected the fallback to be the first configured locale.');
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
