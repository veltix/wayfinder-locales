<?php

namespace Veltix\WayfinderLocales\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Veltix\WayfinderLocales\WayfinderI18nServiceProvider;
use Veltix\WayfinderLocales\WayfinderVariant;

abstract class TestCase extends Orchestra
{
    /**
     * Which laravel/wayfinder line the application under test should believe is
     * installed. Only one line can physically be installed at a time, so the
     * detection is faked before the container boots.
     */
    protected bool $devNext = false;

    protected function setUp(): void
    {
        WayfinderVariant::fake($this->devNext);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        WayfinderVariant::fake(null);

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WayfinderI18nServiceProvider::class];
    }
}
