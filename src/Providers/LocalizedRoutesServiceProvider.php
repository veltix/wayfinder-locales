<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Providers;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\FormRequests;
use Laravel\Wayfinder\Converters\InertiaData;
use Laravel\Wayfinder\Converters\JsonData;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer;
use Veltix\WayfinderLocales\Wayfinder\TypeScriptEmitterExtension;

final class LocalizedRoutesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/wayfinder-locales.php',
            'wayfinder-locales',
        );

        $this->app->singleton(LocaleRouteResolver::class, function ($app): LocaleRouteResolver {
            return new LocaleRouteResolver(
                router: $app->make(Router::class),
                config: $app->make(Repository::class),
            );
        });

        $this->app->singleton(TypeScriptEmitterExtension::class, function ($app): TypeScriptEmitterExtension {
            return new TypeScriptEmitterExtension(
                config: $app->make(Repository::class),
            );
        });

        $this->app->bind(WayfinderRoutes::class, function ($app): WayfinderRoutes {
            return new LocaleAwareRouteTransformer(
                inertiaDataConverter: $app->make(InertiaData::class),
                jsonDataConverter: $app->make(JsonData::class),
                formRequestConverter: $app->make(FormRequests::class),
                config: $app->make(Repository::class),
                localeRouteResolver: $app->make(LocaleRouteResolver::class),
                emitterExtension: $app->make(TypeScriptEmitterExtension::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes(
            [
                __DIR__.'/../../config/wayfinder-locales.php' => config_path('wayfinder-locales.php'),
            ],
            'wayfinder-locales-config',
        );

        $this->registerLocalizedRouteMacro();
    }

    private function registerLocalizedRouteMacro(): void
    {
        if (IlluminateRoute::hasMacro('localized')) {
            return;
        }

        IlluminateRoute::macro('localized', function (array $translations): IlluminateRoute {
            /** @var IlluminateRoute $this */
            $action = $this->getAction();
            $action[(string) config('wayfinder-locales.action_key', 'wayfinder_locales')] = [
                'translations' => $translations,
            ];

            return $this->setAction($action);
        });
    }
}
