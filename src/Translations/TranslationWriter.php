<?php

namespace Veltix\WayfinderLocales\Translations;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Js;

use function Illuminate\Filesystem\join_paths;

class TranslationWriter
{
    public function __construct(
        private Filesystem $files,
    ) {}

    /**
     * @param  array{catalogs: array<string, array<string, string>>, keys: list<string>, replacements: array<string, list<string>>}  $collected
     * @param  list<string>  $locales
     */
    public function write(
        string $dir,
        array $collected,
        array $locales,
        string $default,
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

        $localesPath = join_paths($dir, 'locales.ts');
        $this->writeIfChanged($localesPath, $this->localesModule($locales, $default));
        $written[] = $localesPath;

        $indexPath = join_paths($dir, 'index.ts');
        $this->writeIfChanged($indexPath, $this->indexModule($locales));
        $written[] = $indexPath;

        if ((bool) config('wayfinder-locales.inertia_binding', false)) {
            $inertiaPath = join_paths($dir, 'inertia.ts');
            $this->writeIfChanged($inertiaPath, $this->inertiaModule($default));
            $written[] = $inertiaPath;
        }

        $this->prune($dir, $written);
    }

    /**
     * @param  list<string>  $locales
     */
    private function localesModule(array $locales, string $default): string
    {
        $union = $locales === []
            ? 'string'
            : implode(' | ', array_map(fn ($locale) => (string) Js::from($locale), $locales));

        $list = implode(', ', array_map(fn ($locale) => (string) Js::from($locale), $locales));
        $defaultValue = (string) Js::from($default);

        return <<<TS
        export type Locale = {$union};

        export const locales: Locale[] = [{$list}];

        export const defaultLocale: Locale = {$defaultValue};

        let currentLocale: () => Locale | null = () => null;

        /**
         * Register the app's active locale, either as a value or as a getter
         * re-read on every lookup (e.g. () => page.props.locale).
         */
        export const setLocale = (locale: Locale | (() => Locale | null)): void => {
            currentLocale = typeof locale === "function" ? locale : () => locale;
        };

        export const getLocale = (): Locale | null => currentLocale();

        TS;
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
    private function indexModule(array $locales): string
    {
        $loaderLines = implode("\n", array_map(
            fn ($locale) => '    '.Js::from($locale).': () => import('.Js::from('./'.$locale, JSON_UNESCAPED_SLASHES).'),',
            $locales,
        ));

        $loaders = "{\n{$loaderLines}\n}";

        return str_replace('__LOADERS__', $loaders, $this->runtime());
    }

    /**
     * @inertiajs/react -- a dependency a non-Inertia consumer does not have,
     */
    private function inertiaModule(string $default): string
    {
        return <<<TS
        // This file is auto-generated by veltix/wayfinder-locales.
        // Do not edit it directly, any changes will be overwritten.

        import { router } from '@inertiajs/react';
        import { setUrlDefaults } from '../wayfinder';

        import type { Page } from '@inertiajs/core';
        import type { ReactNode } from 'react';

        type WithApp = { ssr: boolean; page: Page };

        // `router.page` is a real instance property, reassigned on every
        // client-side visit, but it is not part of the published Router type.
        type RouterWithPage = typeof router & { page?: Page };

        /**
         * Registers the active locale for every generated route helper, so no
         * call site passes one.
         *
         * Safe under SSR because Inertia's render function has a single await
         * (resolveComponent) and this callback runs after it, in the same
         * synchronous stretch as renderToString -- nothing can interleave
         * between the write and the reads that consume it. Registering before
         * that await is what races.
         *
         * On the very first client hydration router.page is not yet populated,
         * so ctx.page covers that render.
         */
        export function bindLocale(
            wrap: (app: ReactNode) => ReactNode = (app) => app,
        ): (app: ReactNode, ctx: WithApp) => ReactNode {
            return (app, ctx) => {
                setUrlDefaults(() => ({
                    locale:
                        (ctx.ssr
                            ? ctx.page
                            : ((router as RouterWithPage).page ?? ctx.page)
                        ).props.locale ?? '{$default}',
                }));

                return wrap(app);
            };
        }
        TS;
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
        import { defaultLocale, getLocale, type Locale } from "./locales";
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
