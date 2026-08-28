<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Locale;

use Illuminate\Config\Repository;
use Throwable;

final class DefaultLocaleResolver
{
    /**
     * kept so {@see self::resolve()} can tell whether it needs to resolve
     * again or may reuse {@see self::$cache}.
     */
    private mixed $source = null;

    private bool $resolved = false;

    private ?string $cache = null;

    public function __construct(private readonly Repository $config) {}

    public function resolve(): ?string
    {
        $source = $this->config->get('wayfinder-locales.default_locale');

        if ($this->resolved && $source === $this->source) {
            return $this->cache;
        }

        $this->source = $source;
        $this->resolved = true;

        return $this->cache = $this->resolveFrom($source);
    }

    private function resolveFrom(mixed $source): ?string
    {
        $value = $source;

        if (is_callable($value)) {
            try {
                $value = $value();
            } catch (Throwable) {
                $value = null;
            }
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->firstConfiguredLocale();
    }

    private function firstConfiguredLocale(): ?string
    {
        $locales = array_values(array_filter(
            array_map('strval', (array) $this->config->get('wayfinder-locales.locales', [])),
            static fn (string $locale): bool => $locale !== '',
        ));

        return $locales[0] ?? null;
    }
}
