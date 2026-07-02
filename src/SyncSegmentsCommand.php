<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;

use function Illuminate\Filesystem\join_paths;

/**
 * Scaffolds missing route-segment translation keys into
 * lang/{locale}/{lang_file}.php so newly added localized routes don't silently
 * fall back to their raw segments.
 *
 * Source segments are collected by {@see LocalizedRouteRegistrar} while routes
 * register, so this requires un-cached routes (run `route:clear` first).
 */
class SyncSegmentsCommand extends Command
{
    protected $signature = 'wayfinder-i18n:sync-segments
        {--locale=* : Limit to these locales (creates the lang file when missing)}
        {--dry-run : Report changes without writing}';

    protected $description = 'Scaffold missing route-segment translation keys into lang/{locale}/{lang_file}.php.';

    public function __construct(
        private Filesystem $files,
        private Router $router,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->getLaravel()->routesAreCached()) {
            $this->components->error('Routes are cached, so source segments cannot be collected. Run `php artisan route:clear` and try again.');

            return self::FAILURE;
        }

        // Force route files to load so the registrar has collected segments.
        $this->router->getRoutes();

        $segments = LocalizedRouteRegistrar::collectedSegments();

        if ($segments === []) {
            $this->components->info('No localized route segments found.');

            return self::SUCCESS;
        }

        $langFile = (string) config('wayfinder-i18n.lang_file', 'routes');
        $configLocales = array_values((array) config('wayfinder-i18n.locales', []));

        $requested = array_values((array) $this->option('locale'));

        $locales = $requested !== []
            ? array_values(array_intersect($configLocales, $requested))
            : array_values(array_filter(
                $configLocales,
                fn ($locale) => $this->files->exists($this->langFilePath($locale, $langFile)),
            ));

        if ($locales === []) {
            $this->components->warn('No target locales. Pass --locale=xx to create a new lang file, or create one by hand first.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $wrote = false;

        foreach ($locales as $locale) {
            $path = $this->langFilePath($locale, $langFile);
            $existing = $this->existingKeys($path);

            $missing = array_values(array_diff($segments, $existing));
            $dead = array_values(array_diff($existing, $segments));

            if ($missing === [] && $dead === []) {
                $this->components->twoColumnDetail($locale, '<fg=gray>up to date</>');

                continue;
            }

            if ($missing !== []) {
                $this->components->twoColumnDetail(
                    $locale,
                    '<fg=green>+'.count($missing).($dryRun ? ' (dry run)' : ' added').'</>',
                );

                foreach ($missing as $segment) {
                    $this->line("    <fg=green>+</> {$segment}");
                }

                if (! $dryRun) {
                    $this->writeMissing($path, $locale, $missing);
                    $wrote = true;
                }
            }

            if ($dead !== []) {
                $this->components->twoColumnDetail(
                    $locale,
                    '<fg=yellow>'.count($dead).' unused (left in place)</>',
                );

                foreach ($dead as $segment) {
                    $this->line("    <fg=yellow>?</> {$segment}");
                }
            }
        }

        if ($dryRun) {
            $this->components->info('Dry run — no files written.');
        } elseif (! $wrote) {
            $this->components->info('Everything is up to date.');
        }

        return self::SUCCESS;
    }

    private function langFilePath(string $locale, string $langFile): string
    {
        return join_paths(lang_path(), $locale, $langFile.'.php');
    }

    /**
     * @return list<string>
     */
    private function existingKeys(string $path): array
    {
        if (! $this->files->exists($path)) {
            return [];
        }

        $data = $this->files->getRequire($path);

        return is_array($data) ? array_map('strval', array_keys($data)) : [];
    }

    /**
     * @param  list<string>  $missing
     */
    private function writeMissing(string $path, string $locale, array $missing): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        $stubLines = array_map(
            fn ($segment) => '    '.var_export($segment, true).' => '.var_export($segment, true).', // TODO: translate',
            $missing,
        );

        if (! $this->files->exists($path)) {
            $body = implode("\n", $stubLines);

            $this->files->put($path, <<<PHP
            <?php

            // Translated URL path segments ({$locale}). Generated by wayfinder-i18n:sync-segments.
            // Keys are the original segment; values the localized form.
            return [
            {$body}
            ];

            PHP);

            return;
        }

        $content = $this->files->get($path);
        $pos = strrpos($content, '];');

        if ($pos === false) {
            $this->components->warn("Could not locate the array end in {$path}; skipped.");

            return;
        }

        $insertion = "\n    // Added by wayfinder-i18n:sync-segments\n".implode("\n", $stubLines)."\n";

        $this->files->put($path, substr($content, 0, $pos).$insertion.substr($content, $pos));
    }
}
