<?php

namespace Veltix\WayfinderLocales;

use Laravel\Wayfinder\Converters\Routes as WayfinderRoutesConverter;
use Laravel\Wayfinder\Route as WayfinderRoute;

/**
 * Detects which line of laravel/wayfinder is installed.
 *
 * The `next` branch generates via converter classes and drops the stable
 * `Laravel\Wayfinder\Route` wrapper, so route/action generation has to be
 * routed to the DevNext integration. Translation generation is orthogonal to
 * routes and runs on both lines.
 */
final class WayfinderVariant
{
    private static ?bool $override = null;

    public static function isDevNext(): bool
    {
        if (self::$override !== null) {
            return self::$override;
        }

        return ! class_exists(WayfinderRoute::class)
            && class_exists(WayfinderRoutesConverter::class);
    }

    /**
     * Force the detected variant. Pass null to restore real detection.
     *
     * @internal Testing hook — lets the suite exercise both lines against a
     *           single installed version of laravel/wayfinder.
     */
    public static function fake(?bool $devNext): void
    {
        self::$override = $devNext;
    }
}
