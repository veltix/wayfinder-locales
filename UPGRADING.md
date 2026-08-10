# Upgrading

## v2 → v3

v3 targets **`laravel/wayfinder: dev-next` only**. Support for the stable `^0.1` line is removed:
that branch generates through a different mechanism, and keeping both working meant two code paths,
two config files, and a runtime probe deciding which one you got. If you are on stable `laravel/wayfinder`, stay on v2.

### 1. Composer

```bash
composer require laravel/wayfinder:dev-next veltix/wayfinder-locales:^3.0
```

`illuminate/*` constraints narrow to `^12.0|^13.0`, matching what dev-next itself supports.

### 2. The two config files become one

`config/wayfinder-i18n.php` is gone. Everything lives in `config/wayfinder-locales.php`, published
under the tag `wayfinder-locales-config` (was `wayfinder-i18n-config`).

Merge your published config by hand — the defaults are not all the same:

| v2 | v3 | note |
|---|---|---|
| `wayfinder-i18n.locales` | `wayfinder-locales.locales` | **Move this first.** It is the key the dev-next provider never merged, which is why bilingual apps silently generated English only. |
| `wayfinder-i18n.default` | `wayfinder-locales.default_locale` | Renamed. Default is `'en'` (was `null` on the route side); env var is `WAYFINDER_DEFAULT_LOCALE`, not `WAYFINDER_I18N_DEFAULT_LOCALE`. |
| `wayfinder-i18n.hide_default_prefix` (`true`) | `wayfinder-locales.hide_default_prefix` (`false`) | **The default flips.** The two files disagreed; the route-side `false` won, because that is the value dev-next route generation already used. If you relied on the `wayfinder-i18n` default, set it to `true` explicitly or every generated URL gains a locale prefix. |
| `wayfinder-i18n.lang_file` (`'routes'`) | *removed* | Route segments are declared inline in `Route::localized([...])` now, so there is no segment lang file to name. Its exclusion side effect is preserved: `exclude_groups` defaults to `['routes']`. |
| `wayfinder-i18n.exclude_groups` | `wayfinder-locales.exclude_groups` | Now defaults to `['routes']` rather than `[]`. |
| `wayfinder-i18n.locale_aware_urls` | *removed* | See "locale-aware `route()`" below. |
| `wayfinder-locales.*` (all other keys) | unchanged | `enabled`, `mode`, `strict`, `locale_parameter`, `action_key` keep their names, defaults and env vars. |

`WAYFINDER_I18N_*` environment variables are no longer read.

### 3. The generate command is renamed

```diff
- php artisan wayfinder-i18n:generate
+ php artisan wayfinder-locales:generate
```

Update any `composer.json` / `package.json` script, CI step, or Vite plugin config that invokes it.

Its `--skip-actions`, `--skip-routes`, `--with-form` and `--skip-translations` flags are gone. It
generates translations, full stop; `wayfinder:generate` emits routes and actions, and this package
adds the localized URLs to that run. Only `--path` remains.

`wayfinder-i18n:sync-segments` is **removed**. It scaffolded `lang/{locale}/routes.php` stubs from
segments collected by the stable route registrar. dev-next declares segments inline, so there was
nothing left for it to collect or to write.

### 4. Localized routes are declared per route

v2 registered one concrete route per locale, named `{locale}.{name}`, via a group macro:

```php
// v2
Route::localized(function () {
    Route::get('/search', SearchController::class)->name('search');
});
```

v3 registers one `{locale}`-parameterised route and tags it:

```php
// v3
Route::get('/{locale}/search', SearchController::class)
    ->name('search')
    ->localized(['en' => 'search', 'de' => 'suche']);
```

Route **names no longer carry a locale prefix**. `route('de.search')` becomes
`route('search', ['locale' => 'de'])`. With `hide_default_prefix`, an unprefixed twin named
`{name}.default` is registered for the default locale.

### 5. Locale-aware `route()` is gone

`LocalizedUrlGenerator` rewrote `route('x')` to `route('{activeLocale}.x')`. Those names do not
exist any more, so the generator could never match and has been removed along with the
`locale_aware_urls` config key.

`lroute()` survives, rebased: it fills the locale route parameter.

```php
lroute('search');            // active locale
lroute('search', [], 'de');  // "/de/suche"
```

Plain `route('search', ['locale' => app()->getLocale()])` works too.

### 6. The generated frontend output moved

`resources/js/wayfinder/` belongs to `wayfinder:generate`, which deletes any file there it did not
write. v2 wrote its helper into `wayfinder/index.ts` — on dev-next that clobbers every generated
route — and its locale module into `wayfinder/locales.ts`, which the next `wayfinder:generate` would
delete. v3 writes nothing there.

Everything is under `resources/js/translations/` now, and the runtime is self-contained:

```diff
- import { setLocale, getLocale } from '@/wayfinder';
- import { defaultLocale, type Locale } from '@/wayfinder/locales';
+ import { setLocale, getLocale, defaultLocale, type Locale } from '@/translations/locales';
```

`t()`, `tChoice()` and `loadLocale()` are still imported from `@/translations`.
