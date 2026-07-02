<?php

namespace Veltix\WayfinderLocales;

use Closure;
use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Registers the `Route::localized()` macro. For each configured locale it runs
 * the route-definition closure inside a prefixed/name-prefixed group, then
 * rewrites each newly registered route's static URI segments to their
 * translated form and tags it with a `locale` route default.
 *
 * Concrete routes are registered per locale (no runtime translation), so
 * `route:cache` keeps working.
 *
 * Two route macros provide inline overrides for the lang-file dictionary:
 *   ->paths(['de' => '/anzeigen/{listing}'])     whole-path, per locale
 *   ->segments(['contact' => ['de' => 'kontakt']]) single segment, per locale
 */
class LocalizedRouteRegistrar
{
    /** Route default key holding inline whole-path overrides. */
    public const PATHS_DEFAULT = 'wayfinderI18nPaths';

    /** Route default key holding inline per-segment overrides. */
    public const SEGMENTS_DEFAULT = 'wayfinderI18nSegments';

    /**
     * Source (untranslated) segments seen while localizing routes, used by the
     * `sync-segments` command to scaffold missing lang stubs. A set keyed by
     * segment; segments handled by an inline override are intentionally absent.
     *
     * @var array<string, true>
     */
    private static array $translatableSegments = [];

    public static function register(): void
    {
        if (Router::hasMacro('localized')) {
            return;
        }

        Router::macro('localized', function (Closure $callback): void {
            /** @var Router $this */
            $config = config('wayfinder-i18n');
            $locales = $config['locales'] ?? ['en'];
            $default = $config['default'] ?? 'en';
            $hideDefault = $config['hide_default_prefix'] ?? true;
            $langFile = $config['lang_file'] ?? 'routes';

            foreach ($locales as $locale) {
                $prefix = ($locale === $default && $hideDefault) ? '' : $locale;

                $attributes = ['as' => $locale.'.'];

                if ($prefix !== '') {
                    $attributes['prefix'] = $prefix;
                }

                $existing = [];

                foreach ($this->getRoutes()->getRoutes() as $route) {
                    $existing[spl_object_id($route)] = true;
                }

                $this->group($attributes, $callback);

                foreach ($this->getRoutes()->getRoutes() as $route) {
                    if (! isset($existing[spl_object_id($route)])) {
                        LocalizedRouteRegistrar::localizeRoute($route, $locale, $langFile, $prefix);
                    }
                }
            }
        });

        if (! BaseRoute::hasMacro('paths')) {
            BaseRoute::macro('paths', function (array $paths) {
                /** @var BaseRoute $this */
                return $this->defaults(LocalizedRouteRegistrar::PATHS_DEFAULT, $paths);
            });
        }

        if (! BaseRoute::hasMacro('segments')) {
            BaseRoute::macro('segments', function (array $segments) {
                /** @var BaseRoute $this */
                return $this->defaults(LocalizedRouteRegistrar::SEGMENTS_DEFAULT, $segments);
            });
        }
    }

    /**
     * Translate the static segments of a freshly registered route and tag it
     * with its locale. The leading locale prefix segment and any `{param}`
     * placeholders are left untouched. Inline `->paths()`/`->segments()`
     * overrides take precedence over the lang-file dictionary.
     */
    public static function localizeRoute(BaseRoute $route, string $locale, string $langFile, string $prefix): void
    {
        $pathOverrides = self::overrideData($route, self::PATHS_DEFAULT);
        $segmentOverrides = self::overrideData($route, self::SEGMENTS_DEFAULT);

        // setUri() re-parses the URI and resets binding fields to []. The original
        // {param:field} bindings (e.g. {company:slug}) were already stripped into
        // bindingFields at registration, so capture and restore them across setUri.
        $bindingFields = $route->bindingFields();

        if (isset($pathOverrides[$locale]) && is_string($pathOverrides[$locale])) {
            // Whole-path override: caller supplies the localized path (without the
            // locale prefix); re-apply the prefix and use it verbatim.
            $override = trim($pathOverrides[$locale], '/');
            $uri = $prefix !== '' ? trim($prefix.'/'.$override, '/') : $override;

            $route->setUri($uri === '' ? '/' : $uri);
        } else {
            $segments = explode('/', $route->uri());

            $translated = array_map(function (string $segment, int $index) use ($prefix, $langFile, $locale, $segmentOverrides) {
                if ($segment === '') {
                    return $segment;
                }

                if ($index === 0 && $prefix !== '' && $segment === $prefix) {
                    return $segment;
                }

                if (Str::contains($segment, ['{', '}'])) {
                    return $segment;
                }

                // Inline per-segment override wins over the lang dictionary.
                if (isset($segmentOverrides[$segment][$locale]) && is_string($segmentOverrides[$segment][$locale])) {
                    return $segmentOverrides[$segment][$locale];
                }

                // Record the source segment for `sync-segments`, unless it is
                // handled inline (an override key exists for it in any locale).
                if (! isset($segmentOverrides[$segment])) {
                    self::$translatableSegments[$segment] = true;
                }

                return static::translateSegment($segment, $langFile, $locale);
            }, $segments, array_keys($segments));

            $route->setUri(implode('/', $translated));
        }

        if ($bindingFields !== []) {
            $route->setBindingFields($bindingFields);
        }

        $route->defaults('locale', $locale);

        self::forgetOverrideData($route);
    }

    protected static function translateSegment(string $segment, string $langFile, string $locale): string
    {
        $key = "{$langFile}.{$segment}";

        $translation = trans($key, [], $locale);

        if (! is_string($translation) || $translation === $key) {
            return $segment;
        }

        return $translation;
    }

    /**
     * Distinct source segments collected while localizing routes this boot,
     * sorted. Empty when routes are cached (the closures never ran).
     *
     * @return list<string>
     */
    public static function collectedSegments(): array
    {
        $segments = array_keys(self::$translatableSegments);
        sort($segments);

        return $segments;
    }

    public static function flushCollectedSegments(): void
    {
        self::$translatableSegments = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function overrideData(BaseRoute $route, string $key): array
    {
        $data = $route->defaults[$key] ?? [];

        return is_array($data) ? $data : [];
    }

    private static function forgetOverrideData(BaseRoute $route): void
    {
        unset($route->defaults[self::PATHS_DEFAULT], $route->defaults[self::SEGMENTS_DEFAULT]);
    }
}
