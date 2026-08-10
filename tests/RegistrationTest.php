<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\Routes as WayfinderRoutes;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Wayfinder\LocaleAwareRouteTransformer;

/**
 * The defect this release exists to fix: under dev-next the provider returned
 * from `boot()` before registering anything, so the whole translation pipeline
 * was unreachable — `wayfinder-i18n:generate` simply did not exist.
 */
class RegistrationTest extends TestCase
{
    #[Test]
    public function it_registers_the_generate_command(): void
    {
        $this->assertArrayHasKey('wayfinder-locales:generate', Artisan::all());
    }

    #[Test]
    public function it_no_longer_registers_the_stable_line_sync_segments_command(): void
    {
        $this->assertArrayNotHasKey('wayfinder-i18n:sync-segments', Artisan::all());
        $this->assertArrayNotHasKey('wayfinder-locales:sync-segments', Artisan::all());
    }

    #[Test]
    public function it_merges_one_config_file(): void
    {
        $this->assertSame(['en'], config('wayfinder-locales.locales'));
        $this->assertSame('en', config('wayfinder-locales.default_locale'));
        $this->assertSame('segment', config('wayfinder-locales.mode'));
        $this->assertSame('locale', config('wayfinder-locales.locale_parameter'));
        $this->assertSame('wayfinder_locales', config('wayfinder-locales.action_key'));
        $this->assertSame(['routes'], config('wayfinder-locales.exclude_groups'));
        $this->assertFalse(config('wayfinder-locales.hide_default_prefix'));
    }

    /**
     * The locale list used to live in a second config the dev-next provider
     * never merged. If `wayfinder-i18n` comes back, so does that bug.
     */
    #[Test]
    public function it_does_not_merge_a_second_config_file(): void
    {
        $this->assertNull(config('wayfinder-i18n'));
    }

    #[Test]
    public function it_publishes_the_config_under_one_tag(): void
    {
        $groups = ServiceProvider::publishableGroups();

        $this->assertContains('wayfinder-locales-config', $groups);
        $this->assertNotContains('wayfinder-i18n-config', $groups);
    }

    #[Test]
    public function it_registers_the_localized_route_macro_and_setlocale_alias(): void
    {
        $this->assertTrue(IlluminateRoute::hasMacro('localized'));
        $this->assertArrayHasKey('setlocale', $this->app['router']->getMiddleware());
    }

    /**
     * Route generation is Wayfinder's; the package only swaps the converter it
     * uses. If this binding is lost, localized URLs silently stop being emitted
     * while `wayfinder:generate` still reports success.
     */
    #[Test]
    public function it_binds_the_locale_aware_routes_converter_over_wayfinders(): void
    {
        $this->assertInstanceOf(
            LocaleAwareRouteTransformer::class,
            $this->app->make(WayfinderRoutes::class),
        );
    }
}
