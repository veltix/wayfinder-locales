<?php

namespace Veltix\WayfinderLocales\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Scans Laravel's lang/ directory into flat, dot-keyed catalogs per locale.
 *
 * - lang/{locale}/{group}.php   => nested arrays flattened to "group.nested.key"
 * - lang/{locale}.json          => flat string keys (key === source string)
 * - lang/vendor/{pkg}/{locale}  => "pkg::group.key"
 *
 * The default locale is the source of truth for the generated key set.
 */
class TranslationCollector
{
    public function __construct(
        private Filesystem $files,
        private string $langPath,
    ) {}

    /**
     * @param  list<string>  $locales
     * @param  list<string>  $excludeGroups
     * @return array{
     *     catalogs: array<string, array<string, string>>,
     *     keys: list<string>,
     *     replacements: array<string, list<string>>
     * }
     */
    public function collect(array $locales, string $default, array $excludeGroups = []): array
    {
        $catalogs = [];

        foreach ($locales as $locale) {
            $catalogs[$locale] = $this->collectLocale($locale, $excludeGroups);
        }

        $defaultCatalog = $catalogs[$default] ?? [];

        $keys = array_keys($defaultCatalog);
        sort($keys);

        $replacements = [];

        foreach ($defaultCatalog as $key => $value) {
            $tokens = $this->extractTokens($value);

            if ($tokens !== []) {
                $replacements[$key] = $tokens;
            }
        }

        return [
            'catalogs' => $catalogs,
            'keys' => $keys,
            'replacements' => $replacements,
        ];
    }

    /**
     * @param  list<string>  $excludeGroups
     * @return array<string, string>
     */
    private function collectLocale(string $locale, array $excludeGroups): array
    {
        $catalog = [];

        // PHP group files: lang/{locale}/*.php
        $localeDir = $this->langPath.DIRECTORY_SEPARATOR.$locale;

        if ($this->files->isDirectory($localeDir)) {
            foreach ($this->files->allFiles($localeDir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $group = Str::of($file->getRelativePathname())
                    ->beforeLast('.php')
                    ->replace(DIRECTORY_SEPARATOR, '/')
                    ->toString();

                if (in_array($group, $excludeGroups, true)) {
                    continue;
                }

                $items = $this->files->getRequire($file->getPathname());

                if (! is_array($items)) {
                    continue;
                }

                foreach (Arr::dot($items, $group.'.') as $key => $value) {
                    if (is_scalar($value)) {
                        $catalog[$key] = (string) $value;
                    }
                }
            }
        }

        // JSON file: lang/{locale}.json
        $jsonFile = $this->langPath.DIRECTORY_SEPARATOR.$locale.'.json';

        if ($this->files->exists($jsonFile)) {
            $decoded = json_decode($this->files->get($jsonFile), true);

            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_scalar($value)) {
                        $catalog[(string) $key] = (string) $value;
                    }
                }
            }
        }

        // Vendor namespaces: lang/vendor/{package}/{locale}/*.php
        $vendorDir = $this->langPath.DIRECTORY_SEPARATOR.'vendor';

        if ($this->files->isDirectory($vendorDir)) {
            foreach ($this->files->directories($vendorDir) as $packageDir) {
                $package = basename($packageDir);
                $packageLocaleDir = $packageDir.DIRECTORY_SEPARATOR.$locale;

                if (! $this->files->isDirectory($packageLocaleDir)) {
                    continue;
                }

                foreach ($this->files->allFiles($packageLocaleDir) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $group = Str::of($file->getRelativePathname())
                        ->beforeLast('.php')
                        ->replace(DIRECTORY_SEPARATOR, '/')
                        ->toString();

                    $items = $this->files->getRequire($file->getPathname());

                    if (! is_array($items)) {
                        continue;
                    }

                    foreach (Arr::dot($items, $package.'::'.$group.'.') as $key => $value) {
                        if (is_scalar($value)) {
                            $catalog[$key] = (string) $value;
                        }
                    }
                }
            }
        }

        return $catalog;
    }

    /**
     * Extract unique `:placeholder` token names from a translation string.
     *
     * @return list<string>
     */
    private function extractTokens(string $value): array
    {
        if (! preg_match_all('/:([a-zA-Z][a-zA-Z0-9_]*)/', $value, $matches)) {
            return [];
        }

        $tokens = array_map('strtolower', $matches[1]);

        return array_values(array_unique($tokens));
    }
}
