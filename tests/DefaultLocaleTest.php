<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Tests\Concerns\WritesLangFiles;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;
use function Orchestra\Testbench\Pest\setUp;
use function Orchestra\Testbench\Pest\tearDown;

uses(WritesLangFiles::class);

setUp(function ($parent): void {
    $this->setUpWorkspace([
        'en/messages.php' => ['only_en' => 'English'],
        'de/messages.php' => ['only_de' => 'Deutsch'],
    ]);

    $parent();
});

tearDown(function (): void {
    $this->tearDownWorkspace();
});

defineEnvironment(function (Application $app): void {
    $app->useLangPath($this->workspace.'/lang');
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'de');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->middleware('setlocale')
        ->get('/{locale}/products', fn () => app()->getLocale())
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});

it('seeds the translation key union with the default locale', function (): void {
    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
        ->assertSuccessful();

    $keys = $this->generated('translations/keys.ts');

    expect($keys)->toContain('messages.only_de');
    expect($keys)->not->toContain('messages.only_en');
});

it('emits the default locale as the runtime fallback', function (): void {
    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
        ->assertSuccessful();

    expect($this->generated('translations/locales.ts'))
        ->toContain("export const defaultLocale: Locale = 'de';");
});

it('drives route url generation with the default locale', function (): void {
    /** @var IlluminateRoute $route */
    $route = $this->app['router']->getRoutes()->getByName('products');

    $metadata = $this->app->make(LocaleRouteResolver::class)->resolveForRangerRoute(
        new RangerRoute($route, new Collection, null, null),
    );

    expect($metadata)->not->toBeNull();

    $uris = $metadata->localizedUris;

    expect($uris['de'])->toBe('/produkte');
    expect($uris['en'])->toBe('/{locale}/products');
});

it('drives the unprefixed route registration with the default locale', function (): void {
    $default = $this->app['router']->getRoutes()->getByName('products.default');

    expect($default)->not->toBeNull();
    expect($default->uri())->toBe('produkte');
    expect($default->defaults['locale'])->toBe('de');
});

it('resolves the unprefixed route at the default locales translated segment', function (): void {
    $this->get('/produkte')->assertOk()->assertSee('de');
});

it('no longer resolves the old literal once the twin moves', function (): void {
    $this->get('/products')->assertNotFound();
});

it('leaves the unprefixed route unmoved when the default segment equals the literal', function (): void {
    config()->set('wayfinder-locales.default_locale', 'en');

    $this->app['router']->get('/{locale}/products', fn () => 'ok')
        ->name('products.english_default')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $this->app['router']->getRoutes()->refreshNameLookups();

    $default = $this->app['router']->getRoutes()->getByName('products.english_default.default');

    expect($default)->not->toBeNull();
    expect($default->uri())->toBe('products');
    expect($default->defaults['locale'])->toBe('en');
});

it('warns when the default locale is not in the configured list', function (): void {
    config()->set('wayfinder-locales.default_locale', 'fr');

    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
        ->expectsOutputToContain('is not listed in wayfinder-locales.locales')
        ->assertSuccessful();
});

it('drives the unprefixed route registration with a closure default locale', function (): void {
    config()->set('wayfinder-locales.default_locale', fn (): string => 'de');

    $this->app['router']->get('/{locale}/gadgets', fn () => 'ok')
        ->name('gadgets')
        ->localized(['en' => 'gadgets', 'de' => 'apparate']);

    $this->app['router']->getRoutes()->refreshNameLookups();

    $default = $this->app['router']->getRoutes()->getByName('gadgets.default');

    expect($default)->not->toBeNull();
    expect($default->uri())->toBe('apparate');
    expect($default->defaults['locale'])->toBe('de');
});

it('invokes the resolver closure once per registration pass, not once per route', function (): void {
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
});

it('falls back and completes registration when the resolver throws', function (): void {
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
    expect($default->uri())->toBe('thingamajigs');
    $this->assertSame('en', $default->defaults['locale'], 'Expected the fallback to be the first configured locale.');
});
