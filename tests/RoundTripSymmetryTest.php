<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

/**
 * A minimal, non-Eloquent {@see UrlRoutable} standing in for a model-bound
 * shape. Only outbound URL generation is exercised — {@see getRouteKey()}
 * is what {@see lroute()} and {@see route()} read a bound parameter through
 */
final class RoundTripSymmetryProduct implements UrlRoutable
{
    public function __construct(public readonly string $id = '') {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): self
    {
        return new self((string) $value);
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

defineRoutes(function (Router $router): void {
    $router->middleware('setlocale')
        ->get('/{locale}/contact', fn () => 'contact:'.app()->getLocale())
        ->name('contact')
        ->localized(['en' => 'contact', 'de' => 'kontakt']);

    $router->middleware('setlocale')
        ->get('/{locale?}/about', fn () => 'about:'.app()->getLocale())
        ->name('about')
        ->localized(['en' => 'about', 'de' => 'ueber-uns']);

    $router->middleware('setlocale')
        ->get('/{locale}/products/{product}/reviews', fn () => 'products.reviews:'.app()->getLocale().':'.request()->route('product'))
        ->name('products.reviews')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $router->middleware('setlocale')
        ->get('/{locale}/products/{product}', fn () => 'products.show:'.app()->getLocale().':'.request()->route('product'))
        ->name('products.show')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});

/**
 * @return Collection<int, IlluminateRoute>
 */
function localizedRouteTable(): Collection
{
    $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');
    $actionKey = (string) config('wayfinder-locales.action_key', 'wayfinder_locales');

    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(function (IlluminateRoute $route) use ($parameter, $actionKey): bool {
            if (! isset($route->getAction()[$actionKey])) {
                return false;
            }

            $uri = $route->uri();

            return str_contains($uri, '{'.$parameter.'}') || str_contains($uri, '{'.$parameter.'?}');
        })
        ->values();
}

/**
 * @param  array<string, mixed>  $extraParameters
 */
function resolverUrlFor(IlluminateRoute $route, string $name, array $extraParameters, string $locale): string
{
    $localeParameter = (string) config('wayfinder-locales.locale_parameter', 'locale');

    $metadata = app(LocaleRouteResolver::class)->resolveForRoute($route);

    Assert::assertNotNull($metadata, "Expected localized metadata for [{$name}].");

    $template = $metadata->uriForLocale($locale);

    Assert::assertNotNull($template, "Expected a localized URI template for [{$name}] in locale [{$locale}].");

    $replacements = [
        '{'.$localeParameter.'?}' => $locale,
        '{'.$localeParameter.'}' => $locale,
    ];

    foreach ($extraParameters as $parameterName => $value) {
        $replacements['{'.$parameterName.'}'] = $value instanceof UrlRoutable
            ? (string) $value->getRouteKey()
            : (string) $value;
    }

    return strtr($template, $replacements);
}

/**
 * @param  array<string, mixed>  $extraParameters
 */
function assertRoundTripSymmetry(IlluminateRoute $route, string $name, array $extraParameters, string $locale, string $expectedContent): void
{
    $resolverUrl = resolverUrlFor($route, $name, $extraParameters, $locale);
    $lrouteUrl = lroute($name, $extraParameters, $locale, absolute: false);

    Assert::assertSame(
        $resolverUrl,
        $lrouteUrl,
        "lroute() and the resolver's uriForLocale() disagreed for [{$name}] in locale [{$locale}].",
    );

    $response = test()->get($resolverUrl);

    $response->assertOk();
    Assert::assertSame($expectedContent, $response->getContent());

    Assert::assertSame($locale, app()->getLocale());
}

it('round-trips every localized route shape, for every locale, through both generators', function (): void {
    $locales = (array) config('wayfinder-locales.locales', []);

    $extraParametersByName = [
        'products.reviews' => ['product' => '7'],
        'products.show' => ['product' => new RoundTripSymmetryProduct('7')],
    ];

    $expectedContentByName = [
        'contact' => fn (string $locale): string => "contact:{$locale}",
        'about' => fn (string $locale): string => "about:{$locale}",
        'products.reviews' => fn (string $locale): string => "products.reviews:{$locale}:7",
        'products.show' => fn (string $locale): string => "products.show:{$locale}:7",
    ];

    $routes = localizedRouteTable();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $name = $route->getName();

        Assert::assertNotNull($name, 'Every localized() route in this fixture is expected to be named.');
        Assert::assertArrayHasKey($name, $expectedContentByName, "No expected content registered for [{$name}] — add one alongside the route.");

        $extraParameters = $extraParametersByName[$name] ?? [];

        foreach ($locales as $locale) {
            assertRoundTripSymmetry($route, $name, $extraParameters, $locale, $expectedContentByName[$name]($locale));
        }
    }
});

it('round-trips a tail mode route, for every locale, through both generators', function (): void {
    config()->set('wayfinder-locales.mode', 'tail');

    /** @var Router $router */
    $router = $this->app['router'];

    $route = $router->middleware('setlocale')
        ->get('/{locale}/help/getting-started', fn () => 'help:'.app()->getLocale())
        ->name('help')
        ->localized(['en' => 'help/getting-started', 'de' => 'hilfe/erste-schritte']);

    $router->getRoutes()->refreshNameLookups();

    foreach ((array) config('wayfinder-locales.locales', []) as $locale) {
        assertRoundTripSymmetry($route, 'help', [], $locale, "help:{$locale}");
    }
});
