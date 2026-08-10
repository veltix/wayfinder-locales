<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Route;

use Illuminate\Config\Repository;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Laravel\Ranger\Components\Route as RangerRoute;
use RuntimeException;

final class LocaleRouteResolver
{
    public function __construct(
        private readonly Router $router,
        private readonly Repository $config,
    ) {}

    public function resolveForRangerRoute(RangerRoute $rangerRoute): ?LocaleRouteMetadata
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $route = $this->findIlluminateRoute($rangerRoute);

        if (! $route instanceof IlluminateRoute) {
            return null;
        }

        $action = $route->getAction();
        $raw = $action[$this->actionKey()] ?? null;

        if (! is_array($raw) || ! isset($raw['translations']) || ! is_array($raw['translations'])) {
            return null;
        }

        $translations = $this->normalizeTranslations($raw['translations']);

        if ($translations === []) {
            return $this->strict()
                ? throw new InvalidArgumentException('The localized() translation map cannot be empty.')
                : null;
        }

        $uri = $route->uri();
        [$hasLocaleParameter, $localeOptional] = $this->detectLocaleParameter($uri, $this->localeParameter());

        if (! $hasLocaleParameter) {
            return $this->strict()
                ? throw new RuntimeException(sprintf(
                    'Route [%s] uses localized() but does not contain {%s} or {%s?}.',
                    $route->getName() ?? $uri,
                    $this->localeParameter(),
                    $this->localeParameter(),
                ))
                : null;
        }

        $mode = $this->mode();

        $localizedUris = match ($mode) {
            'segment' => $this->buildSegmentModeUris($uri, $this->localeParameter(), $translations),
            'tail' => $this->buildTailModeUris($uri, $this->localeParameter(), $translations),
            default => throw new InvalidArgumentException(sprintf('Unsupported wayfinder-locales mode [%s].', $mode)),
        };

        if ($localizedUris === []) {
            return null;
        }

        return new LocaleRouteMetadata(
            routeName: $route->getName() ?? '',
            routeUri: $uri,
            localeParameter: $this->localeParameter(),
            localeOptional: $localeOptional,
            locales: array_values(array_keys($translations)),
            translations: $translations,
            localizedUris: $localizedUris,
            mode: $mode,
        );
    }

    private function findIlluminateRoute(RangerRoute $rangerRoute): ?IlluminateRoute
    {
        $routes = $this->router->getRoutes();

        if ($rangerRoute->name() !== null) {
            $named = $routes->getByName($rangerRoute->name());

            if ($named instanceof IlluminateRoute) {
                return $named;
            }
        }

        /** @var list<IlluminateRoute> $all */
        $all = $routes->getRoutes();

        foreach ($all as $route) {
            if ($route->uri() !== $rangerRoute->uri()) {
                continue;
            }

            if ($route->getName() !== null && $rangerRoute->name() !== null && $route->getName() === $rangerRoute->name()) {
                return $route;
            }

            return $route;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, string>
     */
    private function normalizeTranslations(array $translations): array
    {
        $normalized = [];

        foreach ($translations as $locale => $value) {
            if (! is_string($locale) || trim($locale) === '') {
                if ($this->strict()) {
                    throw new InvalidArgumentException('Translation locale keys must be non-empty strings.');
                }

                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                if ($this->strict()) {
                    throw new InvalidArgumentException(sprintf(
                        'Translation value for locale [%s] must be a non-empty string.',
                        $locale,
                    ));
                }

                continue;
            }

            $normalized[trim($locale)] = trim($value, '/');
        }

        return $normalized;
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function detectLocaleParameter(string $uri, string $localeParameter): array
    {
        $required = '{'.$localeParameter.'}';
        $optional = '{'.$localeParameter.'?}';

        if (str_contains($uri, $optional)) {
            return [true, true];
        }

        if (str_contains($uri, $required)) {
            return [true, false];
        }

        return [false, false];
    }

    /**
     * @param  array<string, string>  $translations
     * @return array<string, string>
     */
    private function buildSegmentModeUris(string $uri, string $localeParameter, array $translations): array
    {
        $segments = explode('/', trim($uri, '/'));
        $targetIndex = $this->findStaticSegmentAfterLocale($segments, $localeParameter);

        if ($targetIndex === null) {
            if ($this->strict()) {
                throw new RuntimeException(sprintf(
                    'Unable to infer static segment for segment mode in route URI [%s].',
                    $uri,
                ));
            }

            return [];
        }

        $hideDefault = $this->hideDefaultPrefix();
        $defaultLocale = $this->defaultLocale();

        $output = [];

        foreach ($translations as $locale => $translatedSegment) {
            $localized = $segments;
            $localized[$targetIndex] = $translatedSegment;

            if ($hideDefault && $defaultLocale === $locale) {
                $output[$locale] = '/'.implode('/', $this->stripLocaleSegment($localized, $localeParameter));
            } else {
                $output[$locale] = '/'.implode('/', $localized);
            }
        }

        return $output;
    }

    /**
     * @param  array<string, string>  $translations
     * @return array<string, string>
     */
    private function buildTailModeUris(string $uri, string $localeParameter, array $translations): array
    {
        $segments = explode('/', trim($uri, '/'));
        $localeIndex = $this->findLocaleSegmentIndex($segments, $localeParameter);

        if ($localeIndex === null) {
            return [];
        }

        $prefix = array_slice($segments, 0, $localeIndex + 1);
        $suffix = array_slice($segments, $localeIndex + 1);

        $dynamicSuffix = array_values(array_filter(
            $suffix,
            static fn (string $segment): bool => str_starts_with($segment, '{') && str_ends_with($segment, '}'),
        ));

        $hideDefault = $this->hideDefaultPrefix();
        $defaultLocale = $this->defaultLocale();

        $output = [];

        foreach ($translations as $locale => $translatedTail) {
            $translatedSegments = $translatedTail === ''
                ? []
                : explode('/', trim($translatedTail, '/'));

            if ($dynamicSuffix !== [] && ! str_contains($translatedTail, '{')) {
                $translatedSegments = [...$translatedSegments, ...$dynamicSuffix];
            }

            if ($hideDefault && $defaultLocale === $locale) {
                $prefixWithoutLocale = $this->stripLocaleSegment($prefix, $localeParameter);
                $output[$locale] = '/'.implode('/', [...$prefixWithoutLocale, ...$translatedSegments]);
            } else {
                $output[$locale] = '/'.implode('/', [...$prefix, ...$translatedSegments]);
            }
        }

        return $output;
    }

    /**
     * @param  list<string>  $segments
     */
    private function findStaticSegmentAfterLocale(array $segments, string $localeParameter): ?int
    {
        $localeIndex = $this->findLocaleSegmentIndex($segments, $localeParameter);

        if ($localeIndex === null) {
            return null;
        }

        for ($i = $localeIndex + 1, $max = count($segments); $i < $max; $i++) {
            $segment = $segments[$i];

            if (! str_starts_with($segment, '{') && $segment !== '') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    private function findLocaleSegmentIndex(array $segments, string $localeParameter): ?int
    {
        $required = '{'.$localeParameter.'}';
        $optional = '{'.$localeParameter.'?}';

        foreach ($segments as $index => $segment) {
            if ($segment === $required || $segment === $optional) {
                return $index;
            }
        }

        return null;
    }

    private function isEnabled(): bool
    {
        return (bool) $this->config->get('wayfinder-locales.enabled', true);
    }

    private function strict(): bool
    {
        return (bool) $this->config->get('wayfinder-locales.strict', true);
    }

    private function mode(): string
    {
        return (string) $this->config->get('wayfinder-locales.mode', 'segment');
    }

    private function localeParameter(): string
    {
        return (string) $this->config->get('wayfinder-locales.locale_parameter', 'locale');
    }

    private function actionKey(): string
    {
        return (string) $this->config->get('wayfinder-locales.action_key', 'wayfinder_locales');
    }

    private function hideDefaultPrefix(): bool
    {
        return (bool) $this->config->get('wayfinder-locales.hide_default_prefix', false)
            && $this->defaultLocale() !== null;
    }

    private function defaultLocale(): ?string
    {
        $value = $this->config->get('wayfinder-locales.default_locale');

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function stripLocaleSegment(array $segments, string $localeParameter): array
    {
        $required = '{'.$localeParameter.'}';
        $optional = '{'.$localeParameter.'?}';

        return array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== $required && $segment !== $optional,
        ));
    }
}
