# Laravel 12 + Wayfinder Locales + Inertia + React

This guide walks through integrating `veltix/wayfinder-locales` into a Laravel 12 application using Inertia.js and React with TypeScript. It covers route setup, locale detection, shared Inertia data, and React components for language switching — all with fully typed Wayfinder URL helpers.

## Prerequisites & Installation

### Composer

```bash
composer require veltix/wayfinder-locales
```

### npm

```bash
npm install @inertiajs/react
```

### Publish config

```bash
php artisan vendor:publish --tag=wayfinder-locales-config
```

### Environment variables

```env
WAYFINDER_DEFAULT_LOCALE=en
WAYFINDER_LOCALES_MODE=segment
WAYFINDER_HIDE_DEFAULT_PREFIX=false
```

Set `WAYFINDER_HIDE_DEFAULT_PREFIX=true` to omit the locale prefix for the default language (e.g., `/products` instead of `/en/products`).

## Configuration

The published config file at `config/wayfinder-locales.php`:

```php
return [
    'enabled' => env('WAYFINDER_LOCALES_ENABLED', true),

    // segment: localized value replaces static slug segment
    // tail: localized value is treated as full localized path tail
    'mode' => env('WAYFINDER_LOCALES_MODE', 'segment'),

    // Internal route action key used by Route::localized([...])
    'action_key' => 'wayfinder_locales',

    'locale_parameter' => env('WAYFINDER_LOCALE_PARAMETER', 'locale'),

    // Used when route locale is optional and not provided
    'default_locale' => env('WAYFINDER_DEFAULT_LOCALE', null),

    // When true, the default locale's routes omit the {locale} prefix.
    // e.g. /products instead of /en/products (requires default_locale to be set)
    'hide_default_prefix' => env('WAYFINDER_HIDE_DEFAULT_PREFIX', false),

    // Throw on invalid metadata during generation
    'strict' => env('WAYFINDER_LOCALES_STRICT', true),
];
```

### `hide_default_prefix` behavior

When `hide_default_prefix` is `true` and `default_locale` is `en`:

| Locale | Generated URL |
|---|---|
| `en` | `/products` |
| `et` | `/et/tooted` |

The package automatically registers an unprefixed route for the default locale so Laravel can match both `/products` and `/et/tooted`.

## Route Setup

Use Laravel's native `Route::prefix()` and `->group()` to avoid repeating `{locale}` on every route:

```php
// routes/web.php
use App\Http\Controllers\ProductController;

Route::prefix('{locale?}')
    ->middleware(['web', 'set-locale'])
    ->where(['locale' => '[a-z]{2}'])
    ->group(function () {
        Route::get('products', [ProductController::class, 'index'])
            ->name('products.index')
            ->localized([
                'en' => 'products',
                'et' => 'tooted',
            ]);

        Route::get('products/{product}', [ProductController::class, 'show'])
            ->name('products.show')
            ->localized([
                'en' => 'products',
                'et' => 'tooted',
            ]);
    });
```

You can also define individual routes without a group:

```php
Route::get('{locale}/products', [ProductController::class, 'index'])
    ->name('products.index')
    ->localized(['en' => 'products', 'et' => 'tooted']);
```

With `hide_default_prefix=true`, the package auto-registers additional routes without the `{locale}` prefix for the default locale. You don't need to manually define the unprefixed routes.

### Generate TypeScript helpers

```bash
php artisan wayfinder:generate
```

The generated helpers will include localized URL templates:

```typescript
const indexLocalizedTemplates = { en: "/products", et: "/et/tooted" } as const

// With hide_default_prefix=false:
// const indexLocalizedTemplates = { en: "/en/products", et: "/et/tooted" } as const
```

## SetLocale Middleware

Create a middleware that reads the `{locale}` route parameter and sets the application locale:

```php
// app/Http/Middleware/SetLocale.php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    private const SUPPORTED_LOCALES = ['en', 'et'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale')
            ?? config('wayfinder-locales.default_locale')
            ?? config('app.locale', 'en');

        if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
            abort(404);
        }

        App::setLocale($locale);

        return $next($request);
    }
}
```

Register it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'set-locale' => \App\Http\Middleware\SetLocale::class,
    ]);
})
```

## Inertia Shared Data

Share locale information with every Inertia page via `HandleInertiaRequests`:

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'locale' => App::getLocale(),
        'locales' => ['en', 'et'],
        'defaultLocale' => config('wayfinder-locales.default_locale', 'en'),
    ];
}
```

### TypeScript type declaration

```typescript
// resources/js/types/index.d.ts
export type Locale = 'en' | 'et';

export interface SharedProps {
    locale: Locale;
    locales: Locale[];
    defaultLocale: Locale;
}
```

Extend the Inertia page props:

```typescript
declare module '@inertiajs/react' {
    interface PageProps extends SharedProps {}
}
```

## React Integration

### `useLocale` hook

```typescript
// resources/js/hooks/useLocale.ts
import { usePage } from '@inertiajs/react';
import type { Locale, SharedProps } from '@/types';

export function useLocale() {
    const { locale, locales, defaultLocale } = usePage<SharedProps>().props;

    return { locale, locales, defaultLocale };
}
```

### Set URL defaults in `app.tsx`

Ensure Wayfinder helpers always use the current locale by setting URL defaults on mount and on every Inertia navigation:

```tsx
// resources/js/app.tsx
import { createInertiaApp, router } from '@inertiajs/react';
import { setUrlDefaults } from '@wayfinder/helpers'; // generated by Wayfinder

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true });
        return pages[`./Pages/${name}.tsx`];
    },
    setup({ el, App, props }) {
        // Set initial locale
        const locale = props.initialPage.props.locale as string;
        setUrlDefaults({ locale });

        // Keep locale in sync on every navigation
        router.on('navigate', (event) => {
            const pageLocale = event.detail.page.props.locale as string;
            setUrlDefaults({ locale: pageLocale });
        });

        createRoot(el).render(<App {...props} />);
    },
});
```

### `LanguageSwitcher` component

A reusable component that accepts a `resolveUrl` prop to generate the correct localized URL for the current page:

```tsx
// resources/js/Components/LanguageSwitcher.tsx
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { Locale } from '@/types';

interface Props {
    resolveUrl: (locale: Locale) => string;
}

const localeLabels: Record<Locale, string> = {
    en: 'English',
    et: 'Eesti',
};

export default function LanguageSwitcher({ resolveUrl }: Props) {
    const { locale: currentLocale, locales } = useLocale();

    return (
        <nav aria-label="Language switcher">
            <ul className="flex gap-2">
                {locales.map((locale) => (
                    <li key={locale}>
                        <Link
                            href={resolveUrl(locale)}
                            className={locale === currentLocale ? 'font-bold' : ''}
                        >
                            {localeLabels[locale]}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
```

### Page component example

```tsx
// resources/js/Pages/Products/Index.tsx
import { Head } from '@inertiajs/react';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { index } from '@wayfinder/actions/App/Http/Controllers/ProductController';

interface Props {
    products: { id: number; name: string }[];
}

export default function ProductsIndex({ products }: Props) {
    return (
        <>
            <Head title="Products" />
            <LanguageSwitcher
                resolveUrl={(locale) => index.url({ locale })}
            />
            <ul>
                {products.map((product) => (
                    <li key={product.id}>{product.name}</li>
                ))}
            </ul>
        </>
    );
}
```

For routes with parameters:

```tsx
// resources/js/Pages/Products/Show.tsx
import { Head } from '@inertiajs/react';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { show } from '@wayfinder/actions/App/Http/Controllers/ProductController';

interface Props {
    product: { id: number; name: string };
}

export default function ProductShow({ product }: Props) {
    return (
        <>
            <Head title={product.name} />
            <LanguageSwitcher
                resolveUrl={(locale) => show.url({ locale, product: product.id })}
            />
            <h1>{product.name}</h1>
        </>
    );
}
```

### How locale detection works end-to-end

1. User visits `/et/tooted` (or `/products` when `hide_default_prefix=true`)
2. Laravel matches the route; `SetLocale` middleware reads `{locale}` from route parameters (or falls back to the default locale for unprefixed routes)
3. `App::setLocale()` is called
4. `HandleInertiaRequests` shares the current `locale` with the page
5. React reads `locale` from Inertia shared props
6. `setUrlDefaults({ locale })` ensures all Wayfinder URL helpers use the correct locale
7. `LanguageSwitcher` generates links for each locale using the Wayfinder helper

## Improvements

Potential enhancements for future versions:

- **`supported_locales` config key** — Define the list of supported locales in the package config rather than hardcoding them in middleware and types. The package could validate translations against this list and auto-generate the TypeScript `Locale` union type.

- **Shared `Locale` type export** — Generate and export a `Locale` type in the TypeScript output (e.g., `export type Locale = "en" | "et"`) so consuming code doesn't need to manually maintain the type.

- **Multi-route controller locale support** — When a controller method is registered under multiple HTTP verbs or route patterns, apply locale metadata to all variants.
