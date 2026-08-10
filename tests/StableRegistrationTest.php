<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\LocalizedUrlGenerator;

/**
 * Regression guard: the stable line keeps everything it had, including the
 * route-specific wiring that must NOT leak onto the dev-next path.
 */
class StableRegistrationTest extends TestCase
{
    protected bool $devNext = false;

    #[Test]
    public function it_merges_the_package_config(): void
    {
        $this->assertSame(['en'], config('wayfinder-i18n.locales'));
    }

    #[Test]
    public function it_registers_both_commands(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('wayfinder-i18n:generate', $commands);
        $this->assertArrayHasKey('wayfinder-i18n:sync-segments', $commands);
    }

    #[Test]
    public function it_registers_the_localized_route_macro_and_setlocale_alias(): void
    {
        $this->assertTrue($this->app['router']::hasMacro('localized'));
        $this->assertArrayHasKey('setlocale', $this->app['router']->getMiddleware());
    }

    #[Test]
    public function it_swaps_in_the_locale_aware_url_generator(): void
    {
        $this->assertInstanceOf(LocalizedUrlGenerator::class, $this->app['url']);
    }
}
