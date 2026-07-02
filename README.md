# veltix/wayfinder-locales

Multilingual route + translation generation for [Laravel Wayfinder](https://github.com/laravel/wayfinder).

It **extends** Wayfinder (it does not fork it) to support **multilingual routes with translated
path segments** — one logical route that resolves to a different URL per locale — and generates
**type-safe frontend translation catalogs** from your `lang/` files. Works for any Wayfinder
frontend (React / Vue / Svelte).

```
home    →  /            /de         /fr
search  →  /search      /de/suche   /fr/recherche
listing →  /listings/{l}  /de/anzeigen/{l}  /fr/annonces/{l}
```

## How it works

A localized route is registered **once per locale** (named `{locale}.{base}`, with translated,
prefixed URLs). The generator **collapses** the per-locale variants into a **single** TypeScript
export whose `definition.url` is a locale → URL map, resolved at runtime against the active locale:

```ts
search.definition = {
    methods: ["get", "head"],
    url: { en: "/search", de: "/de/suche", fr: "/fr/recherche" },
    defaultLocale: "en",
} satisfies LocalizedRouteDefinition<["get", "head"]>;

search.url(); // → "/de/suche" when the active locale is "de"
```

## Setup

### 1. Config (`config/wayfinder-i18n.php`)

```php
return [
    'locales' => ['en', 'de', 'fr'],
    'default' => env('WAYFINDER_I18N_DEFAULT_LOCALE', 'en'),
    'hide_default_prefix' => env('WAYFINDER_I18N_HIDE_DEFAULT_PREFIX', true),
    'lang_file' => 'routes',      // lang/{locale}/routes.php holds segment translations
    'exclude_groups' => [],       // lang groups to keep out of frontend catalogs
];
```

When `hide_default_prefix` is true the default locale has no URL prefix (`/search`), other locales
are prefixed (`/de/suche`).

### 2. Segment translations (`lang/{locale}/routes.php`)

```php
// lang/de/routes.php
return ['search' => 'suche', 'listings' => 'anzeigen'];
```

Missing translations fall back to the raw segment. `{param}` placeholders are never translated.

#### Inline overrides

The lang file is the default source, but a route can override it inline — useful for
one-off paths, multi-word slugs, or keeping a route's URLs next to its definition:

```php
Route::localized(function () {
    // whole-path override (give the path without the locale prefix)
    Route::get('/listings/{listing}', [ListingController::class, 'show'])
        ->name('listings.show')
        ->paths(['de' => '/anzeigen/{listing}', 'fr' => '/annonces/{listing}']);

    // single-segment override, leaving the rest to the dictionary
    Route::get('/help/contact', ContactController::class)
        ->name('help.contact')
        ->segments(['contact' => ['de' => 'kontakt', 'fr' => 'contact']]);
});
```

Locales you omit fall back to the dictionary, then the raw segment. Overrides flow
through the same machinery as dictionary translations, so the generated TypeScript and
`route:cache` are unaffected. Inline-handled segments are excluded from `sync-segments`.

### 3. Register localized routes

```php
Route::middleware('setlocale')->group(function () {
    Route::localized(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/search', SearchController::class)->name('search');
        Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
    });
});
```

The `setlocale` middleware applies the matched route's locale via `app()->setLocale()`. Concrete
routes are registered per locale, so `route:cache` keeps working.

### 4. Vite plugin

```ts
import { wayfinderI18n } from "@veltixjs/vite-wayfinder-i18n";

export default defineConfig({
    plugins: [
        // ...
        wayfinderI18n({ formVariants: true }),
    ],
});
```

It wraps `@laravel/vite-plugin-wayfinder`, runs `php artisan wayfinder-i18n:generate`, and also
watches `lang/**` and `config/wayfinder-i18n.php`.

### 5. Frontend wiring

```ts
import { setLocale } from "@/wayfinder";
import { loadLocale } from "@/translations";

setLocale(() => page.props.locale);   // routes follow the active locale
await loadLocale(page.props.locale);  // load the active locale's catalog chunk
```

## Generated output

```
resources/js/
  wayfinder/
    index.ts        # runtime: queryParams, setLocale, getLocale, resolveLocalizedUrl, ...
    locales.ts      # export type Locale = 'en' | 'de' | 'fr'; defaultLocale; locales[]
  routes/ , actions/  # Wayfinder output, localized routes collapsed to one locale-keyed export
  translations/
    index.ts        # t(), tChoice(), loadLocale() + per-locale dynamic-import registry
    keys.ts         # TranslationKey union + per-key TranslationReplacements
    en.ts de.ts fr.ts  # one flat catalog module per locale (own lazy chunk)
```

## Translations

Catalogs come from `lang/{locale}/*.php` (flattened to dot keys) and `lang/{locale}.json`. The
default locale is the source of truth for the generated key set.

```ts
import { t, tChoice } from "@/translations";

t("search.title");                    // "Ausrüstung suchen" (de)
t("search.greeting", { name: "Ada" }); // ":name" → "Ada", with :Name / :NAME case variants
tChoice("search.results", 5);          // Laravel-style pluralization ("|", {0}, [1,*])
```

`t()` is keyed by a generated `TranslationKey` union (typos are compile errors) and requires the
right replacement object per key via `TranslationReplacements`.

## Commands

```
php artisan wayfinder-i18n:generate {--path=} {--skip-actions} {--skip-routes} {--with-form} {--skip-translations}
```

```
php artisan wayfinder-i18n:sync-segments {--locale=*} {--dry-run}
```

Scans registered localized routes and scaffolds any missing segment keys into
`lang/{locale}/{lang_file}.php` as `'segment' => 'segment', // TODO: translate` stubs,
so newly added routes don't silently fall back to raw segments. By default it only
touches locales that already have a segment file (pass `--locale=xx` to create one);
existing entries and formatting are preserved, and unused keys are reported but never
removed. Segments handled by an inline `->paths()`/`->segments()` override are skipped.

Source segments are collected as routes register, so run it with **un-cached** routes
(`php artisan route:clear` first if needed).
