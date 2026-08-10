<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Wayfinder;

use Illuminate\Config\Repository;
use Laravel\Ranger\Components\Route as RangerRoute;
use Laravel\Wayfinder\Langs\TypeScript\Converters\RouteMethod;
use Veltix\WayfinderLocales\Route\LocaleRouteMetadata;

final class TypeScriptEmitterExtension
{
    /**
     * @var array<string, LocaleRouteMetadata>
     */
    private array $metadataByRouteKey = [];

    public function __construct(private readonly Repository $config) {}

    public function register(RangerRoute $route, LocaleRouteMetadata $metadata): void
    {
        $this->metadataByRouteKey[$this->routeKey($route)] = $metadata;
    }

    public function makeRouteMethod(
        RangerRoute $route,
        bool $withForm,
        bool $named = false,
        array $relatedRoutes = [],
    ): RouteMethod {
        $metadata = $this->metadataByRouteKey[$this->routeKey($route)] ?? null;

        if ($metadata === null) {
            return new RouteMethod(
                route: $route,
                withForm: $withForm,
                named: $named,
                relatedRoutes: $relatedRoutes,
            );
        }

        return new LocalizedRouteMethod(
            route: $route,
            withForm: $withForm,
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
        $value = $this->config->get('wayfinder-locales.default_locale');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
