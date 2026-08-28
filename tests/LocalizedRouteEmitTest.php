<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\Registry\TypeScriptConverter;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Wayfinder\LocalizedRouteMethod;
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
    $router->get('/{locale}/products/{product}', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $router->get('/{locale?}/about', fn () => 'ok')
        ->name('about')
        ->localized(['en' => 'about', 'de' => 'ueber-uns']);

    $router->get('/status', fn () => 'ok')->name('status');
});

function localizedRouteEmitExtension(): TypeScriptEmitterExtension
{
    $extension = app(TypeScriptEmitterExtension::class);
    $resolver = app(LocaleRouteResolver::class);

    foreach (['products', 'about', 'status'] as $name) {
        $route = rangerRouteNamed($name);
        $metadata = $resolver->resolveForRangerRoute($route);

        if ($metadata !== null) {
            $extension->register($route, $metadata);
        }
    }

    return $extension;
}

function emittedMethod(string $name): string
{
    return localizedRouteEmitExtension()
        ->makeRouteMethod(rangerRouteNamed($name), withForm: false, named: true)
        ->controllerMethod();
}

it('emits a per locale url template table', function (): void {
    expect(emittedMethod('products'))->toContain(
        'const productsLocalizedTemplates = { en: "/products/{product}", de: "/{locale}/produkte/{product}" } as const',
    );
});

it('selects the template for the locale argument', function (): void {
    expect(emittedMethod('products'))->toContain(
        'return (productsLocalizedTemplates[parsedArgs.locale] ?? products.definition.url)',
    );
});

it('keeps wayfinders placeholder replacement and query params', function (): void {
    $emitted = emittedMethod('products');

    expect($emitted)->toContain('.replace("{product}", parsedArgs.product.toString())');
    expect($emitted)->toContain('+ queryParams(options)');
});

it('narrows the locale argument to the declared locales', function (): void {
    $emitted = emittedMethod('products');

    expect($emitted)->toContain('locale: "en" | "de"');
    expect($emitted)->toContain('product: string | number');
});

it('defaults an optional locale before the template lookup', function (): void {
    expect(emittedMethod('about'))->toContain(
        'if (args?.locale === undefined) { args = { ...(args ?? {}), locale: "en" } }',
    );
});

it('leaves untagged routes to wayfinders own method', function (): void {
    $method = localizedRouteEmitExtension()->makeRouteMethod(rangerRouteNamed('status'), withForm: false, named: true);

    expect($method)->not->toBeInstanceOf(LocalizedRouteMethod::class);
    expect($method)->toBeInstanceOf(RouteMethod::class);
    expect($method->controllerMethod())->not->toContain('LocalizedTemplates');
});

it('uses the localized method for tagged routes', function (): void {
    expect(localizedRouteEmitExtension()->makeRouteMethod(rangerRouteNamed('products'), withForm: false, named: true))
        ->toBeInstanceOf(LocalizedRouteMethod::class);
});
