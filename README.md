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

Because concrete routes are registered per locale (no runtime URL rewriting), `route:cache`
keeps working normally.

---

## Installation

```bash
composer require veltix/wayfinder-locales
npm install -D @veltixjs/vite-wayfinder-i18n
```

The service provider is auto-discovered. On boot it registers, for you:

- the `Route::localized()` macro plus the `->paths()` / `->segments()` route macros,
- the `setlocale` middleware alias,
- a **locale-aware `UrlGenerator`** — plain `route('name')` resolves to the active locale's
  variant (disable via `locale_aware_urls`, see below),
- the `wayfinder-i18n:generate` and `wayfinder-i18n:sync-segments` artisan commands.

Publish the config:

```bash
php artisan vendor:publish --tag=wayfinder-i18n-config
```

> **Requirements:** PHP 8.2+, Laravel 11/12/13, `laravel/wayfinder` ^0.1.14, and (for the Vite
> plugin) `@laravel/vite-plugin-wayfinder` + Vite 7/8.

### 1. Config (`config/wayfinder-i18n.php`)

```php
return [
    // Locales you generate for. The first / `default` is the source of truth for
    // the generated `Locale` union and translation keys.
    'locales' => ['en', 'de', 'fr'],
    'default' => env('WAYFINDER_I18N_DEFAULT_LOCALE', 'en'),

    // When true, the default locale has no URL prefix (`/search`); others are
    // prefixed (`/de/suche`). When false, every locale is prefixed (`/en/search`).
    'hide_default_prefix' => env('WAYFINDER_I18N_HIDE_DEFAULT_PREFIX', true),

    // The lang file (lang/{locale}/{lang_file}.php) holding translated URL segments.
    // This group is always excluded from the generated frontend catalogs.
    'lang_file' => 'routes',

    // Keep plain `route('name')` locale-aware. Disable to manage locale URLs
    // manually (via lroute()) instead of swapping the `url` binding.
    'locale_aware_urls' => env('WAYFINDER_I18N_LOCALE_AWARE_URLS', true),

    // Extra PHP lang groups (file basenames) to keep out of the frontend catalogs.
    'exclude_groups' => [],
];
```

### 2. Register localized routes

Wrap the routes you want localized in `Route::localized()`. Each configured locale gets its own
concrete, prefixed, name-prefixed route:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('setlocale')->group(function () {
    Route::localized(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/search', SearchController::class)->name('search');
        Route::get('/listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
    });
});
```

Leave auth/system routes (Fortify, webhooks, etc.) **outside** `Route::localized()` — they stay
unprefixed and the locale-aware `route()` falls back to them cleanly.

The `setlocale` middleware applies the matched route's `locale` default via `app()->setLocale()`.
It only reads the route — for cookie / `Accept-Language` fallback on unprefixed URLs, use a richer
resolver ([see below](#locale-resolution-middleware)).

---

## Translating route URLs — the two approaches

You translate the **static** URL segments of a route (`{param}` placeholders and the locale prefix
are never touched). There are two ways, and they compose — **inline overrides win over the
dictionary**, which falls back to the raw segment:

```
->paths()  (whole path)  ›  ->segments()  (single segment)  ›  lang dictionary  ›  raw segment
```

### Approach A — Shared segment dictionary (`lang/{locale}/routes.php`)

The default, global source. One file per locale mapping a segment to its translation, applied to
**every** localized route automatically. Best for segments reused across many routes.

```php
// lang/de/routes.php
return [
    'search'   => 'suche',
    'listings' => 'anzeigen',
    'about'    => 'ueber-uns',
];

// lang/fr/routes.php
return [
    'search'   => 'recherche',
    'listings' => 'annonces',
];
```

`/listings/{listing}` → `/de/anzeigen/{listing}`, `/fr/annonces/{listing}`. A segment with no
entry (e.g. `about` in French) falls back to the raw segment. The file name is the `lang_file`
config value (default `routes`) — on older Laravel it lives at `resources/lang/{locale}/routes.php`.

Use `wayfinder-i18n:sync-segments` to scaffold missing keys automatically (see [Commands](#commands)).

### Approach B — Inline in the route definition (`routes/web.php`)

Override right next to the route. Best for one-off paths, multi-word slugs, or keeping a route's
URLs beside its definition. Two macros:

```php
Route::localized(function () {
    // (1) ->paths(): whole-path override, per locale. Give the path WITHOUT the
    //     locale prefix; the prefix is re-applied for you. Overrides everything.
    Route::get('/listings/{listing}', [ListingController::class, 'show'])
        ->name('listings.show')
        ->paths([
            'de' => '/anzeigen/{listing}',
            'fr' => '/annonces/{listing}',
        ]);

    // (2) ->segments(): override individual segments, leaving the rest to the
    //     dictionary. Keyed by the source segment, then locale.
    Route::get('/help/contact', ContactController::class)
        ->name('help.contact')
        ->segments([
            'contact' => ['de' => 'kontakt', 'fr' => 'contact'],
        ]);
});
```

Locales you omit from an override fall back to the dictionary, then the raw segment. Inline-handled
segments are excluded from `sync-segments` reporting, and `route:cache` is unaffected.

> **Tip:** use the dictionary for your shared vocabulary and reach for inline overrides only where a
> route needs something special. They layer cleanly.

---

## The Vite plugin (`@veltixjs/vite-wayfinder-i18n`)

Wraps `@laravel/vite-plugin-wayfinder`, points it at `wayfinder-i18n:generate`, and additionally
watches your `lang/**` files and `config/wayfinder-i18n.php` so localized routes and translation
catalogs regenerate whenever they change.

```ts
// vite.config.ts
import { wayfinderI18n } from "@veltixjs/vite-wayfinder-i18n";

export default defineConfig({
    plugins: [
        laravel({ /* ... */ }),
        wayfinderI18n({ formVariants: true }),
    ],
});
```

All upstream Wayfinder plugin options pass through. Additionally:

| Option         | Default                                   | Purpose                                            |
| -------------- | ----------------------------------------- | -------------------------------------------------- |
| `command`      | `php artisan wayfinder-i18n:generate`     | The artisan command run on start/change            |
| `patterns`     | merged with the defaults                  | Extra watch globs (routes/app/lang/config already watched) |
| `actions`      | `true`                                    | Generate `@/actions` helpers                       |
| `routes`       | `true`                                    | Generate `@/routes` helpers                        |
| `formVariants` | `false`                                   | Also emit `.form` variants                         |
| `path`         | `resources/js`                            | Output root                                        |

Run `npm run dev` and the files under `resources/js/{wayfinder,routes,actions,translations}` stay
in sync as you edit routes and lang files.

---

## Frontend integration

The generator emits these modules (under the `@/` → `resources/js` alias):

| Module               | Exports                                                                 |
| -------------------- | ---------------------------------------------------------------------- |
| `@/wayfinder`        | runtime: `setLocale`, `getLocale`, `resolveLocalizedUrl`, `queryParams`, `applyUrlDefaults`, … |
| `@/wayfinder/locales`| `type Locale`, `defaultLocale`, `locales[]`                            |
| `@/routes`, `@/actions` | route/action helpers (localized routes collapsed to one locale-keyed export) |
| `@/translations`     | `t()`, `tChoice()`, `loadLocale()`                                      |

> **Always link with the generated helpers** (`search.url()`, `listings.show.url({ listing })`),
> never hardcoded path strings. Under translated routes a literal `/search` will 404 in non-default
> locales — the helper resolves to the active locale for you.

### Step 1 — Share locale data from the server

Expose the active locale, the list of locales, and (crucially for the switcher) the **current
page's URL in every locale**. In Inertia, add to `HandleInertiaRequests::share()`:

```php
use Illuminate\Support\Str;

'locale'     => app()->getLocale(),
'locales'    => $this->locales(),          // [['code' => 'en', 'label' => 'English'], ...]
'localeUrls' => $this->localeUrls($request),
```

```php
/** Supported locales with native display labels. */
private function locales(): array
{
    $labels = ['en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français'];

    return collect(config('wayfinder-i18n.locales', ['en']))
        ->map(fn (string $code) => ['code' => $code, 'label' => $labels[$code] ?? strtoupper($code)])
        ->values()->all();
}

/**
 * The current page's URL in each locale, for the language switcher. Localized
 * routes are re-resolved per locale via lroute(); other routes keep the URL.
 */
private function localeUrls(\Illuminate\Http\Request $request): array
{
    $locales = (array) config('wayfinder-i18n.locales', ['en']);
    $route   = $request->route();
    $marker  = $route?->defaults['locale'] ?? null;

    if (! $route || ! is_string($marker)) {
        return collect($locales)->mapWithKeys(fn ($l) => [$l => $request->fullUrl()])->all();
    }

    // Strip the "{locale}." name prefix to get the base route name.
    $base = Str::after((string) $route->getName(), $marker.'.');

    // Route defaults leak the 'locale' marker into parameters(); it's the URL
    // prefix, not a real param, so drop it to avoid a spurious ?locale=… query.
    $params = array_merge($route->parameters(), $request->query());
    unset($params['locale']);

    return collect($locales)
        ->mapWithKeys(fn (string $l) => [$l => lroute($base, $params, $l)])
        ->all();
}
```

`lroute($name, $params, $locale)` (shipped as a global helper) generates a URL for a specific
locale's variant, falling back to the unprefixed route when none exists — ideal for switchers and
sitemaps.

### Step 2 — A shared locale helper (framework-agnostic)

Put the runtime wiring in one adapter-neutral module. It points the route helpers at the live
locale, keeps the active translation chunk loaded across navigations, and exposes `switchLocale`.
It imports `router` from `@inertiajs/core`, so it's identical for React, Vue, and Svelte:

```ts
// resources/js/lib/locale.ts
import { router } from "@inertiajs/core";
import { setLocale as setWayfinderLocale } from "@/wayfinder";
import { loadLocale } from "@/translations";
import { defaultLocale, type Locale } from "@/wayfinder/locales";

let current: Locale = defaultLocale;

/** Call once at startup with the initial locale (from the first Inertia page). */
export function initializeLocale(initial: Locale): void {
    current = initial ?? defaultLocale;
    setWayfinderLocale(() => current); // route helpers (.url()) follow the active locale
    void loadLocale(current);

    router.on("navigate", (e) => {
        current = ((e.detail.page.props.locale as Locale) ?? defaultLocale);
        void loadLocale(current); // preload the new page's catalog chunk
    });
}

/**
 * Switch language: persist the choice, load the target catalog, then visit the
 * current page's URL in that locale (from the shared `localeUrls`), which
 * re-renders server-side in the new locale.
 */
export function switchLocale(target: Locale, localeUrls: Record<string, string>): void {
    if (target === current) return;

    document.cookie = `locale=${target};path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;
    void loadLocale(target);

    const url = localeUrls[target];
    if (url) router.visit(url, { preserveScroll: true, preserveState: false });
}
```

Then call `initializeLocale()` in your app entry, reading the initial locale from
`props.initialPage` (available in every adapter's `setup`):

<details open>
<summary><b>React</b> — <code>resources/js/app.tsx</code></summary>

```tsx
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
import { initializeLocale } from "@/lib/locale";
import { defaultLocale, type Locale } from "@/wayfinder/locales";

createInertiaApp({
    resolve: (name) => /* ...your page resolver... */,
    setup({ el, App, props }) {
        initializeLocale((props.initialPage.props.locale as Locale) ?? defaultLocale);
        createRoot(el).render(<App {...props} />);
    },
});
```

</details>

<details>
<summary><b>Vue</b> — <code>resources/js/app.ts</code></summary>

```ts
import { createInertiaApp } from "@inertiajs/vue3";
import { createApp, h } from "vue";
import { initializeLocale } from "@/lib/locale";
import { defaultLocale, type Locale } from "@/wayfinder/locales";

createInertiaApp({
    resolve: (name) => /* ...your page resolver... */,
    setup({ el, App, props, plugin }) {
        initializeLocale((props.initialPage.props.locale as Locale) ?? defaultLocale);
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
});
```

</details>

<details>
<summary><b>Svelte</b> — <code>resources/js/app.ts</code></summary>

```ts
import { createInertiaApp } from "@inertiajs/svelte";
import { mount } from "svelte";
import { initializeLocale } from "@/lib/locale";
import { defaultLocale, type Locale } from "@/wayfinder/locales";

createInertiaApp({
    resolve: (name) => /* ...your page resolver... */,
    setup({ el, App, props }) {
        initializeLocale((props.initialPage.props.locale as Locale) ?? defaultLocale);
        mount(App, { target: el, props });
    },
});
```

</details>

### Step 3 — Build a language switcher

Read `locale` / `locales` / `localeUrls` from your shared props and call `switchLocale`. Same
component in each framework:

<details open>
<summary><b>React</b> — <code>LanguageSwitcher.tsx</code></summary>

```tsx
import { usePage } from "@inertiajs/react";
import { switchLocale } from "@/lib/locale";
import type { Locale } from "@/wayfinder/locales";

type LocaleOption = { code: Locale; label: string };

export default function LanguageSwitcher() {
    const { locale, locales, localeUrls } = usePage().props as {
        locale: Locale;
        locales: LocaleOption[];
        localeUrls: Record<string, string>;
    };

    return (
        <div className="lang-switcher">
            {locales.map((opt) => (
                <button
                    key={opt.code}
                    type="button"
                    aria-current={locale === opt.code}
                    onClick={() => switchLocale(opt.code, localeUrls)}
                >
                    {opt.label}
                    {locale === opt.code ? " ✓" : ""}
                </button>
            ))}
        </div>
    );
}
```

</details>

<details>
<summary><b>Vue</b> — <code>LanguageSwitcher.vue</code></summary>

```vue
<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { switchLocale } from "@/lib/locale";

const page = usePage();
</script>

<template>
    <div class="lang-switcher">
        <button
            v-for="opt in page.props.locales"
            :key="opt.code"
            type="button"
            :aria-current="page.props.locale === opt.code"
            @click="switchLocale(opt.code, page.props.localeUrls)"
        >
            {{ opt.label }}<span v-if="page.props.locale === opt.code"> ✓</span>
        </button>
    </div>
</template>
```

</details>

<details>
<summary><b>Svelte</b> — <code>LanguageSwitcher.svelte</code></summary>

```svelte
<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import { switchLocale } from "@/lib/locale";

    const active = $derived($page.props.locale);
    const locales = $derived($page.props.locales ?? []);
    const urls = $derived($page.props.localeUrls ?? {});
</script>

<div class="lang-switcher">
    {#each locales as opt (opt.code)}
        <button
            type="button"
            aria-current={active === opt.code}
            onclick={() => switchLocale(opt.code, urls)}
        >
            {opt.label}{#if active === opt.code} ✓{/if}
        </button>
    {/each}
</div>
```

</details>

### Locale resolution middleware

The bundled `setlocale` middleware only applies a **matched route's** locale. For a complete
resolver — so unprefixed default-locale pages and API calls still pick the right language — register
your own middleware in the `web` group that layers route → cookie → `Accept-Language` → default:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supported = (array) config('wayfinder-i18n.locales', ['en']);
        $default   = (string) config('wayfinder-i18n.default', 'en');

        $locale = $this->resolve($request, $supported, $default);
        app()->setLocale($locale);

        $response = $next($request);
        Cookie::queue('locale', $locale, 60 * 24 * 365); // persist for unprefixed nav

        return $response;
    }

    private function resolve(Request $request, array $supported, string $default): string
    {
        $route = $request->route()?->defaults['locale'] ?? null;
        if (is_string($route) && in_array($route, $supported, true)) return $route;

        $cookie = $request->cookie('locale');
        if (is_string($cookie) && in_array($cookie, $supported, true)) return $cookie;

        $preferred = $request->getPreferredLanguage($supported);
        if (is_string($preferred) && in_array($preferred, $supported, true)) return $preferred;

        return $default;
    }
}
```

Register it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\App\Http\Middleware\SetLocale::class]);
})
```

---

## Translations

Beyond routes, the generator turns `lang/{locale}/*.php` (flattened to dot keys) and
`lang/{locale}.json` into type-safe frontend catalogs. The default locale is the source of truth
for the generated key set, and each locale is a lazy, code-split chunk.

```ts
import { t, tChoice } from "@/translations";

t("search.title");                     // "Ausrüstung suchen" (de)
t("search.greeting", { name: "Ada" }); // ":name" → "Ada", with :Name / :NAME case variants
tChoice("search.results", 5);          // Laravel-style pluralization ("|", {0}, [1,*])
```

`t()` is keyed by a generated `TranslationKey` union (typos are compile errors) and requires the
right replacement object per key via `TranslationReplacements`. Exclude groups from the catalogs
with `exclude_groups` in the config (the route-segment file is always excluded).

---

## Commands

```
php artisan wayfinder-i18n:generate {--path=} {--skip-actions} {--skip-routes} {--with-form} {--skip-translations}
```

Generates the route/action helpers and translation catalogs. Usually you don't run it by hand — the
Vite plugin runs it on dev start and on change.

```
php artisan wayfinder-i18n:sync-segments {--locale=*} {--dry-run}
```

Scans registered localized routes and scaffolds any missing segment keys into
`lang/{locale}/{lang_file}.php` as `'segment' => 'segment', // TODO: translate` stubs, so newly
added routes don't silently fall back to raw segments. By default it only touches locales that
already have a segment file (pass `--locale=xx` to create one); existing entries and formatting are
preserved, and unused keys are reported but never removed. Segments handled by an inline
`->paths()` / `->segments()` override are skipped.

Source segments are collected as routes register, so run it with **un-cached** routes
(`php artisan route:clear` first if needed).

---

## License

MIT
