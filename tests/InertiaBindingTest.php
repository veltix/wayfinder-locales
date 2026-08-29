<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Veltix\WayfinderLocales\Tests\Concerns\WritesLangFiles;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\setUp;
use function Orchestra\Testbench\Pest\tearDown;

uses(WritesLangFiles::class);

setUp(function ($parent): void {
    $this->setUpWorkspace(['en/messages.php' => ['a' => 'A'], 'de/messages.php' => ['a' => 'A']]);
    $parent();
});

tearDown(function (): void {
    $this->tearDownWorkspace();
});

defineEnvironment(function (Application $app): void {
    $app->useLangPath($this->workspace.'/lang');
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

it('does not emit the Inertia binding by default', function (): void {
    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    expect(file_exists($this->output.'/translations/inertia.ts'))->toBeFalse();
});

it('emits the binding when the flag is on', function (): void {
    config()->set('wayfinder-locales.inertia_binding', true);

    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    expect($this->generated('translations/inertia.ts'))
        ->toContain('export function bindLocale')
        ->toContain("from '@inertiajs/react'")
        ->toContain('setUrlDefaults');
});

it('reads the live page on the client and the request page under SSR', function (): void {
    config()->set('wayfinder-locales.inertia_binding', true);

    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    $binding = $this->generated('translations/inertia.ts');

    expect($binding)->toContain('ctx.ssr')
        ->toContain('ctx.page')
        ->toContain('router as RouterWithPage');
});

it('falls back to the configured default locale', function (): void {
    config()->set('wayfinder-locales.inertia_binding', true);
    config()->set('wayfinder-locales.default_locale', 'de');

    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    expect($this->generated('translations/inertia.ts'))->toContain("?? 'de'");
});

it('stops emitting the binding when the flag is turned off again', function (): void {
    config()->set('wayfinder-locales.inertia_binding', true);
    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    config()->set('wayfinder-locales.inertia_binding', false);
    $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])->assertSuccessful();

    expect(file_exists($this->output.'/translations/inertia.ts'))->toBeFalse();
});
