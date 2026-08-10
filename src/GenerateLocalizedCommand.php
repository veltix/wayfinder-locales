<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Veltix\WayfinderLocales\Translations\TranslationCollector;
use Veltix\WayfinderLocales\Translations\TranslationWriter;

use function Illuminate\Filesystem\join_paths;

/**
 * Generates the frontend translation output: one lazily-loaded catalog module
 * per locale, the `TranslationKey` union, the `Locale` union plus active-locale
 * accessors, and the `t()` / `tChoice()` runtime.
 *
 * Routes and actions are NOT generated here — `wayfinder:generate` emits those,
 * with localized URL templates supplied by this package's
 * {@see \Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer}.
 */
class GenerateLocalizedCommand extends Command
{
    protected $signature = 'wayfinder-locales:generate {--path= : The resources/js directory to generate into}';

    protected $description = 'Generate TypeScript translation catalogs for your configured locales.';

    public function __construct(private Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $locales = $this->locales();

        if ($locales === []) {
            $this->components->error('No locales configured. Set `wayfinder-i18n.locales`.');

            return self::FAILURE;
        }

        $default = $this->defaultLocale();

        if (! in_array($default, $locales, true)) {
            $this->components->warn(
                "Default locale [{$default}] is not listed in wayfinder-i18n.locales ["
                .implode(', ', $locales).'] — the generated translation keys will be empty.',
            );
        }

        $collected = (new TranslationCollector($this->files, lang_path()))
            ->collect($locales, $default, $this->excludedGroups());

        $base = $this->base();

        (new TranslationWriter($this->files))->write($base, $collected, $locales, $default);

        $this->components->info('Generated translations for ['.implode(', ', $locales)."] in {$base}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) config('wayfinder-i18n.locales', [])),
            static fn (string $locale): bool => $locale !== '',
        ));
    }

    /**
     * The locale whose catalog is the source of truth for the generated key
     * union, and the runtime's fallback when a key is missing.
     */
    private function defaultLocale(): string
    {
        return (string) config('wayfinder-i18n.default', 'en');
    }

    /**
     * Lang groups (file basenames) kept out of the frontend catalogs.
     *
     * @return list<string>
     */
    private function excludedGroups(): array
    {
        return array_values(array_unique(array_map(
            'strval',
            (array) config('wayfinder-i18n.exclude_groups', []),
        )));
    }

    private function base(): string
    {
        $path = $this->option('path') ?? join_paths(resource_path(), 'js');

        return join_paths($path, 'translations');
    }
}
