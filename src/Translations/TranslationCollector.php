<?php

namespace Veltix\WayfinderLocales\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

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
