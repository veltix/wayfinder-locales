<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Routing\UrlGenerator;

/**
 * Drop-in UrlGenerator that makes `route()` locale-aware: when a localized
 * variant `{activeLocale}.{name}` exists it is used, otherwise generation falls
 * back to the given name (so unprefixed routes like Fortify's `login` still work).
 *
 * This means existing `route()` / `redirect()->route()` / `to_route()` calls
 * resolve to the active locale with no call-site changes.
 */
class LocalizedUrlGenerator extends UrlGenerator
{
    public function route($name, $parameters = [], $absolute = true)
    {
        if (is_string($name)) {
            $localizedName = app()->getLocale().'.'.$name;

            if ($localizedName !== $name && $this->routes->hasNamedRoute($localizedName)) {
                return parent::route($localizedName, $parameters, $absolute);
            }
        }

        return parent::route($name, $parameters, $absolute);
    }
}
