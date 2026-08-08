<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutesConverter;
use Laravel\Wayfinder\Route as WayfinderRoute;
use Veltix\WayfinderLocales\DevNext\Providers\LocalizedRoutesServiceProvider;
use Veltix\WayfinderLocales\Middleware\SetLocale;

class WayfinderI18nServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->isDevNextWayfinder()) {
            $this->app->register(LocalizedRoutesServiceProvider::class);

            return;
        }

        $this->mergeConfigFrom(__DIR__.'/../config/wayfinder-i18n.php', 'wayfinder-i18n');

        $this->registerLocaleAwareUrlGenerator();
    }

    public function boot(): void
    {
        if ($this->isDevNextWayfinder()) {
            return;
        }

        LocalizedRouteRegistrar::register();

        $this->app['router']->aliasMiddleware('setlocale', SetLocale::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateLocalizedCommand::class,
                SyncSegmentsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/wayfinder-i18n.php' => config_path('wayfinder-i18n.php'),
            ], 'wayfinder-i18n-config');
        }
    }

    /**
     * The `next` branch of laravel/wayfinder generates via converter classes
     * (and drops the stable `Laravel\Wayfinder\Route` wrapper). When present we
     * defer to the dev-next integration, which extends the Routes converter and
     * inherits its richer generation (models, enums, etc.).
     */
    private function isDevNextWayfinder(): bool
    {
        return ! class_exists(WayfinderRoute::class)
            && class_exists(WayfinderRoutesConverter::class);
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
