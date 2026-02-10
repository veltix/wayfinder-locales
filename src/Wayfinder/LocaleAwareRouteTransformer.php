<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Wayfinder;

use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route;
use Laravel\Wayfinder\Converters\FormRequests;
use Laravel\Wayfinder\Converters\InertiaData;
use Laravel\Wayfinder\Converters\JsonData;
use Laravel\Wayfinder\Converters\Routes as BaseRoutes;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

final class LocaleAwareRouteTransformer extends BaseRoutes
{
    public function __construct(
        InertiaData $inertiaDataConverter,
        JsonData $jsonDataConverter,
        FormRequests $formRequestConverter,
        Repository $config,
        private readonly LocaleRouteResolver $localeRouteResolver,
        private readonly TypeScriptEmitterExtension $emitterExtension,
    ) {
        parent::__construct(
            inertiaDataConverter: $inertiaDataConverter,
            jsonDataConverter: $jsonDataConverter,
            formRequestConverter: $formRequestConverter,
            config: $config,
        );
    }

    /**
     * @param  Collection<Route>  $routes
     */
    public function convert(Collection $routes): array
    {
        foreach ($routes as $route) {
            $metadata = $this->localeRouteResolver->resolveForRangerRoute($route);

            if ($metadata !== null) {
                $this->emitterExtension->register($route, $metadata);
            }
        }

        return parent::convert($routes);
    }

    protected function writeControllerMethodExport(Route $route, string $path): RouteMethod
    {
        $method = $this->emitterExtension->makeRouteMethod(
            route: $route,
            withForm: $this->generateFormVariants(),
            named: false,
            relatedRoutes: [],
        );

        $this->appendContent($path, $method->controllerMethod());

        return $method;
    }

    protected function writeNamedMethodExport(Route $route, string $path): RouteMethod
    {
        $method = $this->emitterExtension->makeRouteMethod(
            route: $route,
            withForm: $this->generateFormVariants(),
            named: true,
            relatedRoutes: [],
        );

        $this->appendContent($path, $method->controllerMethod());

        $this->exports[$path] ??= [];

        foreach ($method->computedMethods() as $routeMethod => $name) {
            $this->exports[$path][] = [
                'originalMethod' => $routeMethod === '__invoke' ? $name : $routeMethod,
                'safeMethod' => $name,
            ];
        }

        return $method;
    }

    protected function writeMultiRouteControllerMethodExport(Collection $routes, string $path): RouteMethod
    {
        $method = $this->emitterExtension->makeRouteMethod(
            route: $routes->first(),
            withForm: $this->generateFormVariants(),
            named: false,
            relatedRoutes: $routes->all(),
        );

        $this->appendContent($path, $method->controllerMethod());

        return $method;
    }
}
