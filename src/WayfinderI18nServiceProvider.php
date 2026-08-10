<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Support\ServiceProvider;
use Veltix\WayfinderLocales\DevNext\Providers\LocalizedRoutesServiceProvider;
use Veltix\WayfinderLocales\Middleware\SetLocale;

class WayfinderI18nServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merged on both lines: it carries `locales`/`default`, which the
        // translation collector needs regardless of how routes are generated.
        $this->mergeConfigFrom(__DIR__.'/../config/wayfinder-i18n.php', 'wayfinder-i18n');

        if ($this->isDevNextWayfinder()) {
            $this->app->register(LocalizedRoutesServiceProvider::class);

            return;
        }

        $this->registerLocaleAwareUrlGenerator();
    }

    public function boot(): void
    {
        $this->registerConsole();

        if ($this->isDevNextWayfinder()) {
            return;
        }

        LocalizedRouteRegistrar::register();

        $this->app['router']->aliasMiddleware('setlocale', SetLocale::class);
    }

    /**
     * Translation generation is orthogonal to route generation, so the
     * generator and the publishable config are available on both lines.
     *
     * `sync-segments` is not: it scaffolds lang stubs from the segments
     * collected by {@see LocalizedRouteRegistrar}, which only runs on the
     * stable line. Under dev-next localized paths are declared per route via
     * `Route::localized([...])`, so the registrar collects nothing and the
     * command would always report "no localized route segments found".
     */
    private function registerConsole(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands(array_filter([
            GenerateLocalizedCommand::class,
            $this->isDevNextWayfinder() ? null : SyncSegmentsCommand::class,
        ]));

        $this->publishes([
            __DIR__.'/../config/wayfinder-i18n.php' => config_path('wayfinder-i18n.php'),
        ], 'wayfinder-i18n-config');
    }

    /**
     * The `next` branch of laravel/wayfinder generates via converter classes
     * (and drops the stable `Laravel\Wayfinder\Route` wrapper). When present we
     * defer ROUTE generation to the dev-next integration, which extends the
     * Routes converter and inherits its richer generation (models, enums, etc.).
     */
    private function isDevNextWayfinder(): bool
    {
        return WayfinderVariant::isDevNext();
    }

    /**
     * Swap the `url` binding for a locale-aware UrlGenerator, mirroring the
     * framework's own registration (session/key resolvers + request/routes rebinding).
     */
    private function registerLocaleAwareUrlGenerator(): void
    {
        if (! ($this->app['config']['wayfinder-i18n.locale_aware_urls'] ?? true)) {
            return;
        }

        $this->app->extend('url', function ($url, $app) {
            $routes = $app['router']->getRoutes();

            $generator = new LocalizedUrlGenerator(
                $routes,
                $app['request'],
                $app['config']['app.asset_url'] ?? null,
            );

            $generator->setSessionResolver(fn () => $app['session'] ?? null);
            $generator->setKeyResolver(fn () => $app->make('config')->get('app.key'));

            $app->rebinding('request', function ($app, $request) {
                $app['url']->setRequest($request);
            });

            $app->rebinding('routes', function ($app, $routes) {
                $app['url']->setRoutes($routes);
            });

            return $generator;
        });
    }
}
