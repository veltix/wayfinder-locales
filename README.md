# veltix/wayfinder-locales

Locale-aware and translated route generation for Laravel Wayfinder.

This package extends Wayfinder without modifying Wayfinder core. It adds first-class support for localized and dynamic routes, including strict TypeScript locale unions.

## Requirements

- PHP 8.2+
- Laravel 12
- `laravel/wayfinder`

## Installation

```bash
composer require veltix/wayfinder-locales
```

Publish config:

```bash
php artisan vendor:publish --tag=wayfinder-locales-config
```

## Usage

Add localized metadata to routes with `localized([...])`:

```php
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('{locale}/product', [ProductController::class, 'index'])
    ->name('product.index')
    ->localized([
        'en' => 'product',
        'et' => 'toode',
    ]);
```

For dynamic routes:

```php
Route::get('{locale}/product/{product}', [ProductController::class, 'show'])
    ->name('product.show')
    ->localized([
        'en' => 'product',
        'et' => 'toode',
    ]);
```

## TypeScript output

Generated APIs preserve Wayfinder ergonomics while adding locale safety:

```ts
ProductController.index.url({ locale: "et" });
// "/et/toode"

ProductController.index.url({ locale: "en" });
// "/en/product"

ProductController.show.url({ locale: "et", product: 42 });
// "/et/toode/42"
```

Invalid locales fail at compile time via locale unions:

```ts
type Locale = "en" | "et";
```

## Translation modes

### `segment` (recommended, default)

Each translation replaces one static slug segment.

- Base route: `/{locale}/product`
- `en => product`, `et => toode`
- URLs: `/en/product`, `/et/toode`

### `tail`

Each translation is a full localized tail after the locale segment.

- Base route: `/{locale}/product`
- `en => catalog/product`, `et => kataloog/toode`
- URLs: `/en/catalog/product`, `/et/kataloog/toode`

## Configuration

`config/wayfinder-locales.php`:

```php
return [
    'enabled' => env('WAYFINDER_LOCALES_ENABLED', true),
    'mode' => env('WAYFINDER_LOCALES_MODE', 'segment'),
    'action_key' => 'wayfinder_locales',
    'locale_parameter' => env('WAYFINDER_LOCALE_PARAMETER', 'locale'),
    'default_locale' => env('WAYFINDER_DEFAULT_LOCALE', null),
    'strict' => env('WAYFINDER_LOCALES_STRICT', true),
];
```

## Integration details

This package integrates through Laravel extension points only:

- Registers a route macro: `Route::localized(array $translations)`
- Rebinds Wayfinder's `Laravel\Wayfinder\Converters\Routes` via the service container
- Resolves locale metadata from route action data at generation time
- Emits locale-aware URL templates and locale literal unions in generated TypeScript

No monkey-patching and no Wayfinder source modifications.
