<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Wayfinder;

use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Veltix\WayfinderLocales\Route\LocaleRouteMetadata;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;

final class TypeScriptEmitterExtension
{
    /**
     * @var array<string, LocaleRouteMetadata>
     */
    private array $metadataByRouteKey = [];

    public function __construct(private readonly LocaleRouteResolver $localeRouteResolver) {}

    public function register(RangerRoute $route, LocaleRouteMetadata $metadata): void
    {
        $this->metadataByRouteKey[$this->routeKey($route)] = $metadata;
    }

    public function makeRouteMethod(
        RangerRoute $route,
        bool $withForm,
        bool $withInertiaComponent = false,
        bool $named = false,
        array $relatedRoutes = [],
    ): RouteMethod {
        $metadata = $this->metadataByRouteKey[$this->routeKey($route)] ?? null;

        if ($metadata === null) {
            return new RouteMethod(
                route: $route,
                withForm: $withForm,
                withInertiaComponent: $withInertiaComponent,
                named: $named,
                relatedRoutes: $relatedRoutes,
            );
        }

        return new LocalizedRouteMethod(
            route: $route,
            withForm: $withForm,
            withInertiaComponent: $withInertiaComponent,
            named: $named,
            relatedRoutes: $relatedRoutes,
            metadata: $metadata,
            defaultLocale: $this->defaultLocale(),
        );
    }

    private function routeKey(RangerRoute $route): string
    {
        return ($route->name() ?? '').'|'.$route->uri().'|'.$route->method();
    }

    private function defaultLocale(): ?string
    {
        return $this->localeRouteResolver->defaultLocale();
    }
}
