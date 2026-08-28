<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\Registry\TypeScriptConverter;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Wayfinder\TypeScriptEmitterExtension;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

beforeEach(function (): void {
    if (! ResultConverter::getRegistry()->hasConverter(TypeScriptConverter::class)) {
        ResultConverter::register(TypeScriptConverter::class);
    }
});

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->get('/{locale?}/products', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});

function strictUrlsEmitted(bool $strict): string
{
    config()->set('wayfinder-locales.strict_urls', $strict);

    $extension = app(TypeScriptEmitterExtension::class);
    $resolver = app(LocaleRouteResolver::class);
    $route = rangerRouteNamed('products');
    $metadata = $resolver->resolveForRangerRoute($route);

    if ($metadata !== null) {
        $extension->register($route, $metadata);
    }

    return $extension
        ->makeRouteMethod($route, withForm: false, named: true)
        ->controllerMethod();
}

it('silently falls back to the default locale by default, preserving existing behaviour', function (): void {
    $emitted = strictUrlsEmitted(strict: false);

    expect($emitted)->toContain('locale: "en"')
        ->not->toContain('throw new Error');
});

it('throws instead of guessing when strict_urls is on', function (): void {
    $emitted = strictUrlsEmitted(strict: true);

    expect($emitted)->toContain('throw new Error')
        ->not->toContain('locale: "en" } }');
});

it('names the route in the message so the offending call site is findable', function (): void {
    expect(strictUrlsEmitted(strict: true))
        ->toContain('[products] was called without a locale');
});
