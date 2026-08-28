<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use Veltix\WayfinderLocales\Locale\DefaultLocaleResolver;
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

final class RoundTripSymmetryPage implements UrlRoutable
{
    public function __construct(
        public readonly string $id = '',
        public readonly string $slug = '',
    ) {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
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
        $bindingField = $route->bindingFieldFor($parameterName);

        $replacements['{'.$parameterName.'}'] = match (true) {
            $value instanceof UrlRoutable && $bindingField !== null => (string) $value->{$bindingField},
            $value instanceof UrlRoutable => (string) $value->getRouteKey(),
            default => (string) $value,
        };
    }

    return strtr($template, $replacements);
}

function expectedLocalizedRouteName(string $name, string $locale, bool $hasPlaceholder = true): string
{
    $defaultLocale = app(DefaultLocaleResolver::class)->resolve();

    if (! $hasPlaceholder) {
        return $locale === $defaultLocale
            ? $name
            : $name.'.locale.'.$locale;
    }

    $hideDefaultPrefix = (bool) config('wayfinder-locales.hide_default_prefix', false);

    return $hideDefaultPrefix && $locale === $defaultLocale
        ? $name.'.default'
        : $name.'.locale.'.$locale;
}

/**
 * @param  array<string, mixed>  $extraParameters
 */
function assertRoundTripSymmetry(IlluminateRoute $route, string $name, array $extraParameters, string $locale, string $expectedContent, bool $hasPlaceholder = true): void
{
    $expectedTwinName = expectedLocalizedRouteName($name, $locale, $hasPlaceholder);

    Assert::assertNotNull(
        app('router')->getRoutes()->getByName($expectedTwinName),
        "Expected a route named [{$expectedTwinName}] to be registered for [{$name}] in locale [{$locale}].",
    );

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

function registerWidenedShapeRoutes(Router $router): void
{
    $router->prefix('cart')->group(function (Router $router): void {
        $router->middleware('setlocale')
            ->get('/{locale?}/items', fn () => 'cart.items:'.app()->getLocale())
            ->name('cart.items')
            ->localized(['en' => 'items', 'de' => 'artikel']);
    });

    $router->name('catalog.')->group(function (Router $router): void {
        $router->middleware('setlocale')
            ->get('/{locale?}/listing', fn () => 'catalog.listing:'.app()->getLocale())
            ->name('listing')
            ->localized(['en' => 'listing', 'de' => 'uebersicht']);
    });

    $router->middleware('setlocale')
        ->get('/{locale}/pages/{page:slug}', fn () => 'pages.show:'.app()->getLocale().':'.request()->route('page'))
        ->name('pages.show')
        ->localized(['en' => 'pages', 'de' => 'seiten']);

    $router->name('shop.')->group(function (Router $router): void {
        $router->prefix('checkout')->group(function (Router $router): void {
            $router->middleware('setlocale')
                ->get('/{locale?}/confirm', fn () => 'shop.confirm:'.app()->getLocale())
                ->name('confirm')
                ->localized(['en' => 'confirm', 'de' => 'bestaetigung']);
        });
    });

    $router->prefix('account')->group(function (Router $router): void {
        $router->name('profile.')->group(function (Router $router): void {
            $router->middleware('setlocale')
                ->get('/{locale?}/settings', fn () => 'profile.settings:'.app()->getLocale())
                ->name('settings')
                ->localized(['en' => 'settings', 'de' => 'einstellungen']);
        });
    });

    $router->getRoutes()->refreshNameLookups();
}

function assertWidenedShapesRoundTripSymmetry(): void
{
    $locales = (array) config('wayfinder-locales.locales', []);
    $defaultLocale = app(DefaultLocaleResolver::class)->resolve();

    $nonDefaultLocales = array_values(array_filter(
        $locales,
        static fn (string $locale): bool => $locale !== $defaultLocale,
    ));

    $orderedLocales = $defaultLocale !== null ? [...$nonDefaultLocales, $defaultLocale] : $locales;

    $extraParametersByName = [
        'pages.show' => ['page' => new RoundTripSymmetryPage(id: 'page-1', slug: 'the-page')],
    ];

    $expectedContentByName = [
        'cart.items' => fn (string $locale): string => "cart.items:{$locale}",
        'catalog.listing' => fn (string $locale): string => "catalog.listing:{$locale}",
        'pages.show' => fn (string $locale): string => "pages.show:{$locale}:the-page",
        'shop.confirm' => fn (string $locale): string => "shop.confirm:{$locale}",
        'profile.settings' => fn (string $locale): string => "profile.settings:{$locale}",
    ];

    $routes = localizedRouteTable()
        ->filter(fn (IlluminateRoute $route): bool => array_key_exists((string) $route->getName(), $expectedContentByName))
        ->values();

    Assert::assertEqualsCanonicalizing(
        array_keys($expectedContentByName),
        $routes->map(fn (IlluminateRoute $route): string => (string) $route->getName())->all(),
        'Expected registerWidenedShapeRoutes() to have registered exactly the routes this driver expects.',
    );

    foreach ($orderedLocales as $locale) {
        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $extraParameters = $extraParametersByName[$name] ?? [];

            assertRoundTripSymmetry($route, $name, $extraParameters, $locale, $expectedContentByName[$name]($locale));
        }
    }
}

it('round-trips grouped, named, and binding-hint route shapes, for every locale, through both generators', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', false);

    registerWidenedShapeRoutes($this->app['router']);

    assertWidenedShapesRoundTripSymmetry();
});

it('round-trips the same grouped, named, and binding-hint route shapes under hide_default_prefix, non-default locales first', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', true);

    registerWidenedShapeRoutes($this->app['router']);

    assertWidenedShapesRoundTripSymmetry();
});

/**
 * @return Collection<int, IlluminateRoute>
 */
function placeholderFreeLocalizedRouteTable(): Collection
{
    $parameter = (string) config('wayfinder-locales.locale_parameter', 'locale');
    $actionKey = (string) config('wayfinder-locales.action_key', 'wayfinder_locales');

    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(function (IlluminateRoute $route) use ($parameter, $actionKey): bool {
            if (! isset($route->getAction()[$actionKey])) {
                return false;
            }

            if (str_contains((string) $route->getName(), '.locale.')) {
                return false;
            }

            $uri = $route->uri();

            return ! str_contains($uri, '{'.$parameter.'}') && ! str_contains($uri, '{'.$parameter.'?}');
        })
        ->values();
}

function registerPlaceholderFreeShapeRoutes(Router $router): void
{
    $router->middleware('setlocale')
        ->get('/', fn () => 'home:'.app()->getLocale())
        ->name('home')
        ->localized(['en' => '', 'de' => '']);

    $router->middleware('setlocale')
        ->get('/product/{product:slug}', fn () => 'product.show:'.app()->getLocale().':'.request()->route('product'))
        ->name('product.show')
        ->localized(['en' => 'product', 'de' => 'produkt']);

    $router->name('catalog.')->group(function (Router $router): void {
        $router->middleware('setlocale')
            ->get('/catalog', fn () => 'catalog.listing:'.app()->getLocale())
            ->name('listing')
            ->localized(['en' => 'catalog', 'de' => 'katalog']);
    });

    $router->name('shop.')->group(function (Router $router): void {
        $router->prefix('checkout')->group(function (Router $router): void {
            $router->middleware('setlocale')
                ->get('/confirm', fn () => 'shop.confirm:'.app()->getLocale())
                ->name('confirm')
                ->localized(['en' => 'confirm', 'de' => 'bestaetigung']);
        });
    });

    $router->getRoutes()->refreshNameLookups();
}

it('round-trips grouped, named, binding-hint, and root route shapes declared without a locale placeholder, for every locale, through both generators', function (): void {
    registerPlaceholderFreeShapeRoutes($this->app['router']);

    $locales = (array) config('wayfinder-locales.locales', []);
    $defaultLocale = app(DefaultLocaleResolver::class)->resolve();

    $nonDefaultLocales = array_values(array_filter(
        $locales,
        static fn (string $locale): bool => $locale !== $defaultLocale,
    ));

    $orderedLocales = $defaultLocale !== null ? [...$nonDefaultLocales, $defaultLocale] : $locales;

    $extraParametersByName = [
        'product.show' => ['product' => new RoundTripSymmetryPage(id: 'product-1', slug: 'the-product')],
    ];

    $expectedContentByName = [
        'home' => fn (string $locale): string => "home:{$locale}",
        'product.show' => fn (string $locale): string => "product.show:{$locale}:the-product",
        'catalog.listing' => fn (string $locale): string => "catalog.listing:{$locale}",
        'shop.confirm' => fn (string $locale): string => "shop.confirm:{$locale}",
    ];

    $routes = placeholderFreeLocalizedRouteTable()
        ->filter(fn (IlluminateRoute $route): bool => array_key_exists((string) $route->getName(), $expectedContentByName))
        ->values();

    Assert::assertEqualsCanonicalizing(
        array_keys($expectedContentByName),
        $routes->map(fn (IlluminateRoute $route): string => (string) $route->getName())->all(),
        'Expected registerPlaceholderFreeShapeRoutes() to have registered exactly the routes this driver expects.',
    );

    foreach ($orderedLocales as $locale) {
        foreach ($routes as $route) {
            $name = (string) $route->getName();
            $extraParameters = $extraParametersByName[$name] ?? [];

            assertRoundTripSymmetry($route, $name, $extraParameters, $locale, $expectedContentByName[$name]($locale), hasPlaceholder: false);
        }
    }
});

it('round-trips a tail mode route declared without a locale placeholder, for every locale, through both generators', function (): void {
    config()->set('wayfinder-locales.mode', 'tail');

    /** @var Router $router */
    $router = $this->app['router'];

    $route = $router->middleware('setlocale')
        ->get('/help/getting-started', fn () => 'help:'.app()->getLocale())
        ->name('help')
        ->localized(['en' => 'help/getting-started', 'de' => 'hilfe/erste-schritte']);

    $router->getRoutes()->refreshNameLookups();

    foreach ((array) config('wayfinder-locales.locales', []) as $locale) {
        assertRoundTripSymmetry($route, 'help', [], $locale, "help:{$locale}", hasPlaceholder: false);
    }
});
