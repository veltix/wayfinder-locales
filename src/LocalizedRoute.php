<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A single logical route collapsed from its per-locale variants. Parameter,
 * verb and controller metadata are taken from the canonical (default-locale)
 * variant; only the URI differs per locale via {@see $localesToUri}.
 */
class LocalizedRoute
{
    /**
     * @param  array<string, string>  $localesToUri  locale => Route::uri() (already JS-escaped)
     */
    public function __construct(
        public readonly string $baseName,
        public readonly Route $canonical,
        public readonly array $localesToUri,
        public readonly string $defaultLocale,
    ) {}

    /**
     * Build a collapsed localized route from a cluster of per-locale variants,
     * asserting that their signatures (params, verbs, controller) are identical.
     *
     * @param  Collection<int, Route>  $variants
     */
    public static function fromCluster(string $baseName, Collection $variants, string $defaultLocale): self
    {
        $canonical = $variants->first(fn (Route $r) => $r->localeMarker() === $defaultLocale)
            ?? $variants->first();

        $paramNames = $canonical->parameters()->map(fn ($p) => $p->name)->values()->all();
        $verbList = $canonical->verbs()->map(fn ($v) => $v->actual)->sort()->values()->all();
        $controller = $canonical->controller();
        $method = $canonical->method();

        foreach ($variants as $variant) {
            if ($variant->parameters()->map(fn ($p) => $p->name)->values()->all() !== $paramNames) {
                throw new InvalidArgumentException("Localized route [{$baseName}] has divergent parameters across locales.");
            }

            if ($variant->verbs()->map(fn ($v) => $v->actual)->sort()->values()->all() !== $verbList) {
                throw new InvalidArgumentException("Localized route [{$baseName}] has divergent HTTP verbs across locales.");
            }

            if ($variant->controller() !== $controller || $variant->method() !== $method) {
                throw new InvalidArgumentException("Localized route [{$baseName}] maps to divergent controller actions across locales.");
            }
        }

        $localesToUri = $variants
            ->mapWithKeys(fn (Route $r) => [$r->localeMarker() => $r->uri()])
            ->all();

        return new self($baseName, $canonical, $localesToUri, $defaultLocale);
    }
}
