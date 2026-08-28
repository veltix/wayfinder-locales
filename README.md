# veltix/wayfinder-locales

Localized route URLs and type-safe TypeScript translation catalogs for
[Laravel Wayfinder](https://github.com/laravel/wayfinder).

One logical route, a different URL per locale:

```
products       →  /products        /de/produkte      /fr/produits
products.show  →  /products/{id}   /de/produkte/{id} /fr/produits/{id}
```

…plus `t()` / `tChoice()` over your `lang/` files, with a `TranslationKey` union so a typo is a
build error rather than a string that renders as itself.

> **This is v3. It targets `laravel/wayfinder: dev-next` (the `next` branch) and nothing else.**
> The stable `^0.1` line is not supported. See [UPGRADING.md](UPGRADING.md).

## Requirements

- PHP 8.2+
- Laravel 13
- `laravel/wayfinder: dev-next`

## Installation

```bash
composer require veltix/wayfinder-locales
php artisan vendor:publish --tag=wayfinder-locales-config
```

The service provider is auto-discovered. On boot it registers:

- the `Route::localized()` macro,
- the `setlocale` middleware alias,
- the `wayfinder-locales:generate` artisan command,
- a `Routes` converter binding that adds localized URL templates to Wayfinder's own generation.

## How it works

The two halves of the package are independent, and only one of them generates files.

**Routes.** `Route::localized()` tags the route with a per-locale path segment map and registers a
concrete route per locale — `/de/produkte` alongside the original English route (`/en/products` if
the URI declares a `{locale}` placeholder, plain `/products` if it doesn't — see
[Localized routes](#localized-routes) for both forms) — so the translated URLs the frontend
actually visits are matched inbound by Laravel's own router, not just emitted outbound. At
generation time the same metadata drives the package's converter — bound over Wayfinder's
`Converters\Routes` — which turns it into a template table the generated function picks from. So
localized routes come out of `wayfinder:generate`, not out of a second generator.

**Translations.** `wayfinder-locales:generate` reads `lang/` and writes the frontend catalogs. It
has nothing to do with routing and never writes into `resources/js/wayfinder` — that directory is
Wayfinder's, and `wayfinder:generate` deletes anything there it did not write itself.

## Configuration

Everything lives in `config/wayfinder-locales.php`. There is one locale list and one default
locale, shared by both halves.

```php
return [
    'locales' => ['en', 'de'],
    'default_locale' => env('WAYFINDER_DEFAULT_LOCALE', 'en'),
    // 'default_locale' => fn () => Setting::get('locale', 'en'),

    'enabled' => env('WAYFINDER_LOCALES_ENABLED', true),
    'mode' => env('WAYFINDER_LOCALES_MODE', 'segment'),
    'strict' => env('WAYFINDER_LOCALES_STRICT', true),

    'locale_parameter' => env('WAYFINDER_LOCALE_PARAMETER', 'locale'),
    'hide_default_prefix' => env('WAYFINDER_HIDE_DEFAULT_PREFIX', false),

    'exclude_groups' => ['routes'],
    'action_key' => 'wayfinder_locales',
];
```

| key | what it does |
|---|---|
| `locales` | Every locale generated for. Drives the `Locale` union, the catalog modules, and the locales `setlocale` will accept. |
| `default_locale` | Seeds the `TranslationKey` union, is the runtime's fallback for a missing key, and is the locale whose URL prefix `hide_default_prefix` drops. Should be in `locales`. Accepts a plain string or a `callable(): string` — resolved once per route-registration pass, not once per route, so it's safe to back with a database lookup or a settings cache. A throwing or empty-returning callable falls back to the first entry in `locales`. |
| `enabled` | Turn off localized URL emission without unwinding your `Route::localized()` calls. |
| `mode` | `segment` replaces the first static slug segment after the locale. `tail` treats the translation as the whole localized path tail. |
| `strict` | Throw on malformed `localized()` metadata instead of skipping the route. |
| `locale_parameter` | The URI parameter carrying the locale. |
| `hide_default_prefix` | Register an unprefixed twin (`{name}.default`) for the default locale and emit its URL without the prefix. |
| `exclude_groups` | Lang groups kept out of the frontend catalogs. |
| `action_key` | Route action key `localized()` stashes its map under. Change only on a collision. |

## Localized routes

`localized()` accepts a route declared either of two ways. **Prefer the placeholder-free form** —
it's the one that leaves your existing `route()` calls alone.

### Without a locale placeholder (preferred)

```php
use Illuminate\Support\Facades\Route;

Route::middleware('setlocale')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->name('products.show')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});
```

The declared URI carries no locale segment. `localized()` prepends the locale prefix itself when
it builds each per-locale twin — `/de/produkte` alongside the original, unmodified `/products` —
so the base route's parameter list is untouched. Adding `localized()` to a route declared this way
never changes what `route('products.show', $product)` does.

For every locale other than the default, `localized()` registers a concrete twin route —
`products.locale.de` at `/de/produkte` — so inbound requests match the translated URL. The
default locale needs no twin: the route as declared already serves it, unprefixed, independently
of `hide_default_prefix`.

### With a `{locale}` placeholder

```php
Route::middleware('setlocale')->group(function () {
    Route::get('/{locale}/products', [ProductController::class, 'index'])
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    Route::get('/{locale}/products/{product}', [ProductController::class, 'show'])
        ->name('products.show')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});
```

Use `{locale?}` if the segment may be omitted; the generated function fills in `default_locale`.

This form makes `locale` a real route parameter, which matters if other code reads it off the
route directly (`$route->parameter('locale')`). It has a cost applying it to an **existing**
route: `{locale}`/`{locale?}` becomes that route's first parameter, and Laravel's `route()` maps
positional arguments by position — so `route('products.show', $product)` now binds `$product` to
`locale` instead, and every positional call site for that route has to be found and rewritten to
pass `locale` explicitly. On a route with several call sites, that migration is easy to miss
until it breaks at runtime. The placeholder-free form doesn't have this cost, which is why it's
the default recommendation above.

This form also registers a `products.locale.de` twin for German, exactly like the placeholder-free
form does. The default locale differs by config: with `hide_default_prefix => true` and
`default_locale => 'en'`, `localized()` registers an *unprefixed* twin named `products.default`
bound to `en`, so `/products` and `/en/products` both resolve. With `hide_default_prefix => false`
(the default), the default locale gets its own `products.locale.en` twin at `/en/products` instead,
same as every other locale.

However it's named, if a route already has that exact name, the new one is silently shadowed
(first registered wins) unless `strict` is on, in which case registration throws instead. These
per-locale twin routes exist purely for inbound matching; they are excluded from Wayfinder's
generated output, so they never produce a client-callable function of their own.

Registering one route per locale multiplies your route table by roughly the number of configured
locales — visible in `php artisan route:list`. (Roughly: the placeholder-free form's default
locale reuses the declared route rather than adding a twin, so a two-locale route becomes two
entries in that form and three in the `{locale}` form.) Fine at the route counts most apps have;
worth knowing if you have both many routes and many locales.

Then run Wayfinder as usual:

```bash
php artisan wayfinder:generate
```

```ts
import products from '@/wayfinder/routes/products';

products.url({ locale: 'de' });              // "/de/produkte"
products.show.url({ locale: 'de', product: 7 }); // "/de/produkte/7"
```

The `locale` argument is typed to the locales that route declares, so `products.url({ locale: 'es' })`
is a type error.

On the server, `lroute()` fills the locale parameter in for you:

```php
lroute('products');            // active locale
lroute('products', [], 'de');  // "/de/produkte"
```

`localized()` registers a concrete route per locale, named `{name}.locale.{locale}`, carrying
that locale's translated segment. `lroute()` routes through the one for the locale you ask for,
so what comes back is the translated URL rather than the base route's own URI with the locale
parameter filled in.

A route with **no** locale parameter is generated unchanged, so unprefixed routes — Fortify's
`login`, say — keep working through the same call.

## Translations

Point `locales` at your lang directories and generate:

```bash
php artisan wayfinder-locales:generate
```

```
resources/js/translations/
├── en.ts          # flat catalog, its own lazily-loaded chunk
├── de.ts
├── keys.ts        # TranslationKey union + per-key placeholder types
├── locales.ts     # Locale union, locales[], defaultLocale, setLocale/getLocale
└── index.ts       # t(), tChoice(), loadLocale()
```

`lang/{locale}/{group}.php` becomes dotted keys (`messages.nested.key`), `lang/{locale}.json`
contributes its keys verbatim, and `lang/vendor/{package}/{locale}` becomes `package::group.key`.
The default locale's catalog is the source of truth for the key union.

Tell the runtime which locale is active once, at boot — a getter is re-read on every lookup, which
is what you want with Inertia:

```ts
import { loadLocale } from '@/translations';
import { setLocale } from '@/translations/locales';

setLocale(() => usePage().props.locale);
await loadLocale('de');
```

```ts
import { t, tChoice } from '@/translations';

t('messages.greeting', { name: 'Ada' });  // placeholders are typed per key
tChoice('messages.apples', 3);
```

Missing keys fall back to the default locale's catalog, then to the key itself — the same order
Laravel's `__()` uses.

## Commands

| command | |
|---|---|
| `php artisan wayfinder:generate` | Wayfinder's own. Emits routes and actions, localized URLs included. |
| `php artisan wayfinder-locales:generate [--path=]` | Emits the translation output. `--path` is the JS root, default `resources/js`. |

## License

MIT
