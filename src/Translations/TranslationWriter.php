<?php

namespace Veltix\WayfinderLocales\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Js;

use function Illuminate\Filesystem\join_paths;

/**
 * Emits the lazy, code-split frontend translation output:
 *   translations/{locale}.ts  - one flat catalog module per locale (own chunk)
 *   translations/keys.ts       - TranslationKey union + TranslationReplacements
 *   translations/index.ts      - t()/tChoice()/loadLocale() runtime + loader registry
 */
class TranslationWriter
{
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * @param  array{catalogs: array<string, array<string, string>>, keys: list<string>, replacements: array<string, list<string>>}  $collected
     * @param  list<string>  $locales
     * @param  bool  $localeStoreInLocalesModule  Import getLocale() from
     *                                            wayfinder/locales instead of
     *                                            wayfinder/index (dev-next,
     *                                            where index.ts is Wayfinder's).
     */
    public function write(
        string $dir,
        array $collected,
        array $locales,
        string $default,
        bool $localeStoreInLocalesModule = false,
    ): void {
        $this->files->ensureDirectoryExists($dir);

        $written = [];

        foreach ($locales as $locale) {
            $path = join_paths($dir, $locale.'.ts');
            $this->writeIfChanged($path, $this->localeModule($collected['catalogs'][$locale] ?? []));
            $written[] = $path;
        }

        $keysPath = join_paths($dir, 'keys.ts');
        $this->writeIfChanged($keysPath, $this->keysModule($collected['keys'], $collected['replacements']));
        $written[] = $keysPath;

        $indexPath = join_paths($dir, 'index.ts');
        $this->writeIfChanged($indexPath, $this->indexModule($locales, $localeStoreInLocalesModule));
        $written[] = $indexPath;

        $this->prune($dir, $written);
    }

    /**
     * @param  array<string, string>  $catalog
     */
    private function localeModule(array $catalog): string
    {
        $json = $catalog === []
            ? '{}'
            : json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<TS
        const messages: Record<string, string> = {$json};

        export default messages;

        TS;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $replacements
     */
    private function keysModule(array $keys, array $replacements): string
    {
        $union = $keys === []
            ? 'string'
            : implode("\n    | ", array_map(fn ($key) => (string) Js::from($key), $keys));

        if ($keys === []) {
            $keyType = 'export type TranslationKey = string;';
        } else {
            $keyType = "export type TranslationKey =\n    | {$union};";
        }

        if ($replacements === []) {
            $replacementsType = 'export type TranslationReplacements = {};';
        } else {
            $lines = [];

            foreach ($replacements as $key => $tokens) {
                $fields = implode('; ', array_map(
                    fn ($token) => "{$token}: string | number",
                    $tokens,
                ));

                $lines[] = '    '.Js::from($key).": { {$fields} };";
            }

            $body = implode("\n", $lines);
            $replacementsType = "export type TranslationReplacements = {\n{$body}\n};";
        }

        return <<<TS
        {$keyType}

        {$replacementsType}

        TS;
    }

    /**
     * @param  list<string>  $locales
     */
    private function indexModule(array $locales, bool $localeStoreInLocalesModule = false): string
    {
        $loaderLines = implode("\n", array_map(
            fn ($locale) => '    '.Js::from($locale).': () => import('.Js::from('./'.$locale, JSON_UNESCAPED_SLASHES).'),',
            $locales,
        ));

        $loaders = "{\n{$loaderLines}\n}";

        return str_replace(
            ['__IMPORTS__', '__LOADERS__'],
            [$this->importBlock($localeStoreInLocalesModule), $loaders],
            $this->runtime(),
        );
    }

    private function importBlock(bool $localeStoreInLocalesModule): string
    {
        if ($localeStoreInLocalesModule) {
            return 'import { defaultLocale, getLocale, type Locale } from "../wayfinder/locales";';
        }

        return 'import { getLocale } from "../wayfinder";'."\n"
            .'import { defaultLocale, type Locale } from "../wayfinder/locales";';
    }

    private function writeIfChanged(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        if (! $this->files->exists($path) || $this->files->get($path) !== $content) {
            $this->files->put($path, $content);
        }
    }

    /**
     * @param  list<string>  $writtenPaths
     */
    private function prune(string $dir, array $writtenPaths): void
    {
        if (! $this->files->isDirectory($dir)) {
            return;
        }

        $kept = collect($writtenPaths)->map(fn ($path) => realpath($path) ?: $path)->flip();

        foreach ($this->files->files($dir) as $file) {
            $path = $file->getPathname();

            if (! $kept->has(realpath($path) ?: $path)) {
                $this->files->delete($path);
            }
        }
    }

    private function runtime(): string
    {
        return <<<'TS'
        __IMPORTS__
        import type { TranslationKey, TranslationReplacements } from "./keys";

        export type Catalog = Partial<Record<TranslationKey, string>>;

        type Replacements = Record<string, string | number>;

        type ReplacementsFor<K extends TranslationKey> =
            K extends keyof TranslationReplacements
                ? TranslationReplacements[K]
                : Replacements | undefined;

        const loaders: Record<Locale, () => Promise<{ default: Catalog }>> = __LOADERS__;

        const catalogs: Partial<Record<Locale, Catalog>> = {};

        /**
         * Fetch and cache a locale's message catalog (its own lazily-loaded chunk).
         */
        export const loadLocale = async (locale: Locale): Promise<void> => {
            if (!catalogs[locale]) {
                catalogs[locale] = (await loaders[locale]()).default;
            }
        };

        export const hasLocale = (locale: Locale): boolean => Boolean(catalogs[locale]);

        const activeLocale = (): Locale =>
            ((getLocale() as Locale | null) ?? defaultLocale);

        const lookup = (key: TranslationKey): string => {
            const loc = activeLocale();
            return catalogs[loc]?.[key] ?? catalogs[defaultLocale]?.[key] ?? key;
        };

        const ucfirst = (value: string): string =>
            value.length === 0 ? value : value.charAt(0).toUpperCase() + value.slice(1);

        const interpolate = (line: string, replacements?: Replacements): string => {
            if (!replacements) {
                return line;
            }

            const map: Record<string, string> = {};

            for (const key in replacements) {
                const value = String(replacements[key]);
                map[":" + key] = value;
                map[":" + ucfirst(key)] = ucfirst(value);
                map[":" + key.toUpperCase()] = value.toUpperCase();
            }

            const tokens = Object.keys(map).sort((a, b) => b.length - a.length);

            let result = line;

            for (const token of tokens) {
                result = result.split(token).join(map[token]);
            }

            return result;
        };

        /**
         * Translate a key for the active locale, falling back to the default
         * locale and finally the key itself. Mirrors Laravel's `__()`.
         */
        export const t = <K extends TranslationKey>(
            key: K,
            replacements?: ReplacementsFor<K>,
        ): string => interpolate(lookup(key), replacements as Replacements | undefined);

        const pluralIndex = (locale: string, count: number): number => {
            switch (locale) {
                case "fr":
                case "pt":
                case "pt-br":
                    return count === 0 || count === 1 ? 0 : 1;
                default:
                    return count === 1 ? 0 : 1;
            }
        };

        const matchCondition = (condition: string, count: number): boolean => {
            if (condition.includes(",")) {
                const [from, to] = condition.split(",", 2).map((part) => part.trim());

                if (to === "*") {
                    return count >= Number(from);
                }

                if (from === "*") {
                    return count <= Number(to);
                }

                return count >= Number(from) && count <= Number(to);
            }

            return Number(condition) === count;
        };

        const chooseSegment = (line: string, count: number, locale: string): string => {
            const segments = line.split("|");

            for (const segment of segments) {
                const match = /^[{[]([^[\]{}]*)[}\]](.*)/s.exec(segment);

                if (match && matchCondition(match[1].trim(), count)) {
                    return match[2].trim();
                }
            }

            const stripped = segments.map((segment) =>
                segment.replace(/^[{[]([^[\]{}]*)[}\]]/, "").trim(),
            );

            return stripped[pluralIndex(locale, count)] ?? stripped[0] ?? line;
        };

        /**
         * Translate a key with pluralization. Mirrors Laravel's `trans_choice`.
         */
        export const tChoice = <K extends TranslationKey>(
            key: K,
            count: number,
            replacements?: ReplacementsFor<K>,
        ): string => {
            const line = chooseSegment(lookup(key), count, activeLocale());

            return interpolate(line, {
                count,
                ...((replacements as Replacements | undefined) ?? {}),
            });
        };

        TS;
    }
}
