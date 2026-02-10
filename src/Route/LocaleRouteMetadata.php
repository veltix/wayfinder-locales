<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Route;

final readonly class LocaleRouteMetadata
{
    /**
     * @param  list<string>  $locales
     * @param  array<string, string>  $translations
     * @param  array<string, string>  $localizedUris
     */
    public function __construct(
        public string $routeName,
        public string $routeUri,
        public string $localeParameter,
        public bool $localeOptional,
        public array $locales,
        public array $translations,
        public array $localizedUris,
        public string $mode,
    ) {
    }

    public function hasLocale(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    public function localeUnionType(): string
    {
        return implode(
            ' | ',
            array_map(static fn (string $locale): string => '"'.$locale.'"', $this->locales),
        );
    }

    public function uriForLocale(string $locale): ?string
    {
        return $this->localizedUris[$locale] ?? null;
    }
}
