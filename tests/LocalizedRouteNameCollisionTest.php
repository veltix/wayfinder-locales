<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

use function Orchestra\Testbench\Pest\defineEnvironment;

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

it('throws under strict mode when a locale route name collides', function (): void {
    config()->set('wayfinder-locales.strict', true);

    Route::get('/manual', fn () => 'manual')->name('products.locale.de');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches('/products\.locale\.de/');

    Route::get('/{locale?}/products', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);
});

it('silently shadows the collision under non strict mode', function (): void {
    config()->set('wayfinder-locales.strict', false);

    Route::get('/manual', fn () => 'manual')->name('products.locale.de');

    Route::get('/{locale?}/products', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $this->app['router']->getRoutes()->refreshNameLookups();

    expect($this->app['router']->getRoutes()->getByName('products.locale.de')->uri())->toBe('manual');
});
