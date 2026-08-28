<?php

declare(strict_types=1);

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;
use Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer;

it('registers the generate command', function (): void {
    expect(Artisan::all())->toHaveKey('wayfinder-locales:generate');
});

it('no longer registers the stable line sync segments command', function (): void {
    expect(Artisan::all())
        ->not->toHaveKey('wayfinder-i18n:sync-segments')
        ->not->toHaveKey('wayfinder-locales:sync-segments');
});

it('merges one config file', function (): void {
    expect(config('wayfinder-locales.locales'))->toBe(['en']);
    expect(config('wayfinder-locales.default_locale'))->toBe('en');
    expect(config('wayfinder-locales.mode'))->toBe('segment');
    expect(config('wayfinder-locales.locale_parameter'))->toBe('locale');
    expect(config('wayfinder-locales.action_key'))->toBe('wayfinder_locales');
    expect(config('wayfinder-locales.exclude_groups'))->toBe(['routes']);
    expect(config('wayfinder-locales.hide_default_prefix'))->toBeFalse();
});

it('does not merge a second config file', function (): void {
    expect(config('wayfinder-i18n'))->toBeNull();
});

it('publishes the config under one tag', function (): void {
    $groups = ServiceProvider::publishableGroups();

    expect($groups)->toContain('wayfinder-locales-config');
    expect($groups)->not->toContain('wayfinder-i18n-config');
});

it('registers the localized route macro and setlocale alias', function (): void {
    expect(IlluminateRoute::hasMacro('localized'))->toBeTrue();
    expect($this->app['router']->getMiddleware())->toHaveKey('setlocale');
});

it('binds the locale aware routes converter over wayfinders', function (): void {
    expect($this->app->make(WayfinderRoutes::class))->toBeInstanceOf(LocaleAwareRouteTransformer::class);
});
