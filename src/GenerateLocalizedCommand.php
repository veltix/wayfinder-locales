<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Veltix\WayfinderLocales\Route\LocaleRouteResolver;
use Veltix\WayfinderLocales\Translations\TranslationCollector;
use Veltix\WayfinderLocales\Translations\TranslationWriter;
use Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer;

use function Illuminate\Filesystem\join_paths;

/**
 * {@see LocaleAwareRouteTransformer}.
 */
class GenerateLocalizedCommand extends Command
{
    protected $signature = 'wayfinder-locales:generate {--path= : The resources/js directory to generate into}';

    protected $description = 'Generate TypeScript translation catalogs for your configured locales.';

    public function __construct(
        private Filesystem $files,
        private readonly LocaleRouteResolver $localeRouteResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $locales = $this->locales();

        if ($locales === []) {
            $this->components->error('No locales configured. Set `wayfinder-locales.locales`.');

            return self::FAILURE;
        }

        $default = $this->defaultLocale();

        if (! in_array($default, $locales, true)) {
            $this->components->warn(
                "Default locale [{$default}] is not listed in wayfinder-locales.locales ["
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
            array_map('strval', (array) config('wayfinder-locales.locales', [])),
            static fn (string $locale): bool => $locale !== '',
        ));
    }

    private function defaultLocale(): string
    {
        return $this->localeRouteResolver->defaultLocale() ?? 'en';
    }

    /**
     * @return list<string>
     */
    private function excludedGroups(): array
    {
        return array_values(array_unique(array_map(
            'strval',
            (array) config('wayfinder-locales.exclude_groups', []),
        )));
    }

    private function base(): string
    {
        $path = $this->option('path') ?? join_paths(resource_path(), 'js');

        return join_paths($path, 'translations');
    }
}
