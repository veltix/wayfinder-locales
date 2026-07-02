<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Support\Collection;
use Laravel\Wayfinder\Route as WayfinderRoute;

/**
 * Extends the upstream Wayfinder route wrapper to expose the underlying
 * Illuminate route, so the generator can read the locale marker and the
 * raw (untransformed) route name used for localized-cluster grouping.
 */
class Route extends WayfinderRoute
{
    public function __construct(
        public BaseRoute $baseRoute,
        Collection $paramDefaults,
        ?string $forcedScheme,
        ?string $forcedRoot,
    ) {
        parent::__construct($baseRoute, $paramDefaults, $forcedScheme, $forcedRoot);
    }

    /**
     * The locale this route was registered for, set by the localized registrar
     * via `->defaults('locale', $locale)`. Null for non-localized routes.
     */
    public function localeMarker(): ?string
    {
        $locale = $this->baseRoute->defaults['locale'] ?? null;

        return is_string($locale) ? $locale : null;
    }

    /**
     * The raw route name as registered, before Wayfinder's `name()` transform.
     */
    public function rawName(): ?string
    {
        return $this->baseRoute->getName();
    }
}
