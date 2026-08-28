<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;

use function Orchestra\Testbench\Pest\defineEnvironment;

final class AdoptionShapesPage implements UrlRoutable
{
    /** @var array<int, self> */
    public static array $all = [];

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
        foreach (self::$all as $candidate) {
            if ($field === 'slug' && $candidate->slug === $value) {
                return $candidate;
            }

            if ($field === null && $candidate->id === $value) {
                return $candidate;
            }
        }

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

it('does not duplicate a route group prefix onto a localized routes twins', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', true);

    /** @var Router $router */
    $router = $this->app['router'];

    $router->prefix('cart')->group(function (Router $router): void {
        $router->middleware('setlocale')
            ->get('/{locale?}/items', fn () => 'cart:'.app()->getLocale())
            ->name('cart.items');
    });

    $router->getRoutes()->refreshNameLookups();

    $router->getRoutes()->getByName('cart.items')
        ->localized(['en' => 'items', 'de' => 'artikel']);

    $router->getRoutes()->refreshNameLookups();

    $default = $router->getRoutes()->getByName('cart.items.default');
    $localeTwin = $router->getRoutes()->getByName('cart.items.locale.de');

    expect($default)->not->toBeNull();
    expect($default->uri())->toBe('cart/items');

    expect($localeTwin)->not->toBeNull();
    expect($localeTwin->uri())->toBe('cart/de/artikel');
});

it('keeps the column binding hint on a localized routes locale twin', function (): void {
    AdoptionShapesPage::$all = [
        new AdoptionShapesPage(id: 'some-slug', slug: 'decoy-slug'),
        new AdoptionShapesPage(id: 'real-id', slug: 'some-slug'),
    ];

    /** @var Router $router */
    $router = $this->app['router'];

    $router->middleware(['setlocale', SubstituteBindings::class])
        ->get('/{locale}/page/{page:slug}', fn (AdoptionShapesPage $page) => $page->id)
        ->name('page.show')
        ->localized(['en' => 'page', 'de' => 'seite']);

    $router->getRoutes()->refreshNameLookups();

    $twin = $router->getRoutes()->getByName('page.show.locale.de');

    expect($twin)->not->toBeNull();

    $response = $this->get('/de/seite/some-slug');

    $response->assertOk();
    expect($response->getContent())->toBe('real-id');

    expect($twin->bindingFields())->toBe(['page' => 'slug']);
});

it('completes generation for a localized route once hide_default_prefix creates an unprefixed twin', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', true);

    $this->app['router']->middleware('setlocale')
        ->get('/{locale?}/gadgets', fn () => 'ok')
        ->name('gadgets')
        ->localized(['en' => 'gadgets', 'de' => 'apparate']);

    $this->app['router']->getRoutes()->refreshNameLookups();

    $routes = new Collection([
        rangerRouteNamed('gadgets'),
        rangerRouteNamed('gadgets.default'),
        rangerRouteNamed('gadgets.locale.de'),
    ]);

    $results = $this->app->make(WayfinderRoutes::class)->convert($routes);

    expect($results)->not->toBeEmpty();
});

it('does not duplicate a route group prefix when localized() is chained inside a still-open group', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', true);

    /** @var Router $router */
    $router = $this->app['router'];

    $router->name('shop.')->group(function (Router $router): void {
        $router->prefix('cart')->group(function (Router $router): void {
            $router->middleware('setlocale')
                ->get('/{locale?}/items', fn () => 'cart:'.app()->getLocale())
                ->name('items')
                ->localized(['en' => 'items', 'de' => 'artikel']);
        });
    });

    $router->getRoutes()->refreshNameLookups();

    $default = collect($router->getRoutes()->getRoutes())
        ->first(fn (IlluminateRoute $route): bool => str_contains((string) $route->getName(), '.default'));

    $localeTwin = collect($router->getRoutes()->getRoutes())
        ->first(fn (IlluminateRoute $route): bool => str_contains((string) $route->getName(), '.locale.de'));

    expect($default)->not->toBeNull();
    expect($default->uri())->toBe('cart/items');

    expect($localeTwin)->not->toBeNull();
    expect($localeTwin->uri())->toBe('cart/de/artikel');
});

it('does not duplicate a route group name when localized() is chained inside a still-open group', function (): void {
    config()->set('wayfinder-locales.hide_default_prefix', true);

    /** @var Router $router */
    $router = $this->app['router'];

    $router->name('shop.')->group(function (Router $router): void {
        $router->prefix('cart')->group(function (Router $router): void {
            $router->middleware('setlocale')
                ->get('/{locale?}/items', fn () => 'cart:'.app()->getLocale())
                ->name('items')
                ->localized(['en' => 'items', 'de' => 'artikel']);
        });
    });

    $router->getRoutes()->refreshNameLookups();

    $localeTwin = collect($router->getRoutes()->getRoutes())
        ->first(fn (IlluminateRoute $route): bool => str_contains((string) $route->getName(), '.locale.de'));

    expect($localeTwin)->not->toBeNull();
    expect($localeTwin->getName())->toBe('shop.items.locale.de');

    expect(lroute('shop.items', [], 'de', absolute: false))->toBe('/cart/de/artikel');
});
