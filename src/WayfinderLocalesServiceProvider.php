<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\FormRequests;
use Laravel\Wayfinder\Converters\InertiaData;
use Laravel\Wayfinder\Converters\JsonApiData;
use Laravel\Wayfinder\Converters\JsonData;
use Laravel\Wayfinder\Converters\ResourceData;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;
use ReflectionProperty;
use RuntimeException;
use Veltix\WayfinderLocales\Locale\DefaultLocaleResolver;
use Veltix\WayfinderLocales\Middleware\SetLocale;
use Veltix\WayfinderLocales\Route\LocaleRouteMetadata;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer;
use Veltix\WayfinderLocales\Wayfinder\TypeScriptEmitterExtension;

class WayfinderLocalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wayfinder-locales.php', 'wayfinder-locales');

        $this->app->singleton(DefaultLocaleResolver::class, fn ($app): DefaultLocaleResolver => new DefaultLocaleResolver(
            config: $app->make(Repository::class),
        ));

        $this->app->singleton(LocaleRouteResolver::class, fn ($app): LocaleRouteResolver => new LocaleRouteResolver(
            router: $app->make(Router::class),
            config: $app->make(Repository::class),
            defaultLocaleResolver: $app->make(DefaultLocaleResolver::class),
        ));

        $this->app->singleton(TypeScriptEmitterExtension::class, fn ($app): TypeScriptEmitterExtension => new TypeScriptEmitterExtension(
            defaultLocaleResolver: $app->make(DefaultLocaleResolver::class),
        ));

        $this->app->bind(WayfinderRoutes::class, fn ($app): WayfinderRoutes => new LocaleAwareRouteTransformer(
            inertiaDataConverter: $app->make(InertiaData::class),
            jsonDataConverter: $app->make(JsonData::class),
            resourceDataConverter: $app->make(ResourceData::class),
            jsonApiDataConverter: $app->make(JsonApiData::class),
            formRequestConverter: $app->make(FormRequests::class),
            config: $app->make(Repository::class),
            localeRouteResolver: $app->make(LocaleRouteResolver::class),
            emitterExtension: $app->make(TypeScriptEmitterExtension::class),
        ));
    }

    public function boot(): void
    {
        $this->registerLocalizedRouteMacro();

        $this->app['router']->aliasMiddleware('setlocale', SetLocale::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            GenerateLocalizedCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/wayfinder-locales.php' => config_path('wayfinder-locales.php'),
        ], 'wayfinder-locales-config');
    }

    /**
     * time by {@see LocaleRouteResolver::resolveForRoute()}, which is also
     * route is left registered: {@see LocaleRouteResolver} reads its `uri()`
     */
    private function registerLocalizedRouteMacro(): void
    {
        if (IlluminateRoute::hasMacro('localized')) {
            return;
        }

        $withoutGroupStack = static function (Router $router, callable $addRoute): IlluminateRoute {
            $groupStackProperty = new ReflectionProperty($router, 'groupStack');
            $originalGroupStack = $groupStackProperty->getValue($router);

            $groupStackProperty->setValue($router, []);

            try {
                return $addRoute();
            } finally {
                $groupStackProperty->setValue($router, $originalGroupStack);
            }
        };

        IlluminateRoute::macro('localized', function (array $translations) use ($withoutGroupStack): IlluminateRoute {
            /** @var IlluminateRoute $this */
            $action = $this->getAction();
            $actionKey = (string) config('wayfinder-locales.action_key', 'wayfinder_locales');
            $action[$actionKey] = [
                'translations' => $translations,
            ];

            $this->setAction($action);

            $localeRouteResolver = app(LocaleRouteResolver::class);
            $metadata = $localeRouteResolver->resolveForRoute($this);

            $hideDefault = (bool) config('wayfinder-locales.hide_default_prefix', false);
            $defaultLocale = $localeRouteResolver->defaultLocale();
            $localeParameter = (string) config('wayfinder-locales.locale_parameter', 'locale');
            $requiredPlaceholder = '{'.$localeParameter.'}';
            $optionalPlaceholder = '{'.$localeParameter.'?}';
            $hasLocalePlaceholder = str_contains($this->uri(), $requiredPlaceholder) || str_contains($this->uri(), $optionalPlaceholder);

            if ($hasLocalePlaceholder && $hideDefault && is_string($defaultLocale) && $defaultLocale !== '') {
                $uri = $this->uri();

                $segments = explode('/', trim($uri, '/'));
                $stripped = array_values(array_filter(
                    $segments,
                    static fn (string $s): bool => $s !== $requiredPlaceholder && $s !== $optionalPlaceholder,
                ));

                $unprefixedUri = implode('/', $stripped);
                $translatedDefaultUri = $metadata?->uriForLocale($defaultLocale);

                if ($translatedDefaultUri !== null) {
                    $unprefixedUri = trim($translatedDefaultUri, '/');
                }

                /** @var Router $router */
                $router = app(Router::class);

                $methods = $this->methods();
                $routeAction = $this->getAction();

                unset($routeAction['as'], $routeAction['prefix'], $routeAction[$actionKey]);

                $defaultRoute = $withoutGroupStack($router, fn (): IlluminateRoute => $router->addRoute($methods, $unprefixedUri, $routeAction));
                $defaultRoute->setBindingFields($this->bindingFields());
                $defaultRoute->setWheres($this->wheres);
                $defaultRoute->defaults($localeParameter, $defaultLocale);

                if ($this->getName() !== null) {
                    $defaultRoute->name($this->getName().LocaleRouteMetadata::DEFAULT_TWIN_SUFFIX);
                }

                foreach ($this->excludedMiddleware() as $middleware) {
                    $defaultRoute->withoutMiddleware($middleware);
                }
            } elseif (! $hasLocalePlaceholder && is_string($defaultLocale) && $defaultLocale !== '') {
                $defaultUri = $metadata?->uriForLocale($defaultLocale);

                if ($defaultUri !== null && trim($defaultUri, '/') !== trim($this->uri(), '/')) {
                    $this->setUri(trim($defaultUri, '/'));
                }

                $this->defaults($localeParameter, $defaultLocale);
            }

            /** @var Router $router */
            $router = app(Router::class);

            if ($metadata !== null) {
                $methods = $this->methods();
                $strict = (bool) config('wayfinder-locales.strict', true);

                foreach ($metadata->locales as $locale) {
                    $template = $metadata->uriForLocale($locale);

                    if ($template === null) {
                        continue;
                    }

                    if (! str_contains($template, $requiredPlaceholder) && ! str_contains($template, $optionalPlaceholder)) {
                        continue;
                    }

                    $concreteUri = str_replace([$optionalPlaceholder, $requiredPlaceholder], $locale, $template);
                    $localeRouteName = $this->getName() !== null ? $this->getName().'.locale.'.$locale : null;

                    if ($strict && $localeRouteName !== null) {
                        $collides = false;

                        foreach ($router->getRoutes()->getRoutes() as $existingRoute) {
                            if ($existingRoute->getName() === $localeRouteName) {
                                $collides = true;

                                break;
                            }
                        }

                        if ($collides) {
                            throw new RuntimeException(sprintf(
                                'Route::localized() could not register [%s] for locale [%s]: a route named [%s] already exists.',
                                $this->getName() ?? $concreteUri,
                                $locale,
                                $localeRouteName,
                            ));
                        }
                    }

                    $routeAction = $this->getAction();
                    unset($routeAction['as'], $routeAction['prefix'], $routeAction[$actionKey]);

                    $localeRoute = $withoutGroupStack($router, fn (): IlluminateRoute => $router->addRoute($methods, $concreteUri, $routeAction));
                    $localeRoute->setBindingFields($this->bindingFields());
                    $localeRoute->setWheres($this->wheres);
                    $localeRoute->defaults($localeParameter, $locale);

                    if ($localeRouteName !== null) {
                        $localeRoute->name($localeRouteName);
                    }

                    foreach ($this->excludedMiddleware() as $middleware) {
                        $localeRoute->withoutMiddleware($middleware);
                    }
                }
            }

            return $this;
        });
    }
}
