<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Veltix\WayfinderLocales\WayfinderLocalesServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [WayfinderLocalesServiceProvider::class];
    }
}
