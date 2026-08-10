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
        $router->get('/{locale}/products', fn () => 'ok')
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

    #[Test]
    public function the_default_locale_drives_the_unprefixed_route_registration(): void
    {
        $default = $this->app['router']->getRoutes()->getByName('products.default');

        $this->assertNotNull($default);
        $this->assertSame('products', $default->uri());
        $this->assertSame('de', $default->defaults['locale']);
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
