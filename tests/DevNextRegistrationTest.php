<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Translation generation is orthogonal to route generation. Deferring ROUTE
 * generation to the DevNext integration must not take the translation
 * pipeline (or the package config it reads) down with it.
 */
class DevNextRegistrationTest extends TestCase
{
    protected bool $devNext = true;

    #[Test]
    public function it_merges_the_package_config_under_dev_next(): void
    {
        $this->assertSame(['en'], config('wayfinder-i18n.locales'));
        $this->assertSame('en', config('wayfinder-i18n.default'));
        $this->assertSame('routes', config('wayfinder-i18n.lang_file'));
    }

    #[Test]
    public function it_registers_the_generate_command_under_dev_next(): void
    {
        $this->assertArrayHasKey('wayfinder-i18n:generate', Artisan::all());
    }

    #[Test]
    public function it_does_not_register_the_route_only_sync_segments_command_under_dev_next(): void
    {
        $this->assertArrayNotHasKey('wayfinder-i18n:sync-segments', Artisan::all());
    }

    #[Test]
    public function it_publishes_the_package_config_under_dev_next(): void
    {
        $this->assertContains(
            'wayfinder-i18n-config',
            ServiceProvider::publishableGroups(),
        );
    }
}
