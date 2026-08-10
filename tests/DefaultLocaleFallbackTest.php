<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;

/**
 * `wayfinder-locales.default_locale` ships as null, so a dev-next app that
 * never set it must keep falling back to `wayfinder-i18n.default`.
 */
class DefaultLocaleFallbackTest extends TestCase
{
    protected bool $devNext = true;

    private string $workspace;

    private string $output;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/wayfinder-i18n-'.uniqid();
        $this->output = $this->workspace.'/js';

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->workspace.'/lang/de');
        $files->put($this->workspace.'/lang/de/messages.php', '<?php return ["only_de" => "de"];');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app->useLangPath($this->workspace.'/lang');
        $app['config']->set('wayfinder-i18n.locales', ['de']);
        $app['config']->set('wayfinder-i18n.default', 'de');
    }

    #[Test]
    public function it_falls_back_to_the_i18n_default_when_the_route_side_value_is_unset(): void
    {
        $this->assertNull(config('wayfinder-locales.default_locale'));

        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "export const defaultLocale: Locale = 'de';",
            file_get_contents($this->output.'/wayfinder/locales.ts'),
        );
        $this->assertStringContainsString(
            'messages.only_de',
            file_get_contents($this->output.'/translations/keys.ts'),
        );
    }

    #[Test]
    public function it_warns_when_the_default_locale_is_not_in_the_configured_list(): void
    {
        config()->set('wayfinder-locales.default_locale', 'fr');

        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->expectsOutputToContain('is not listed in wayfinder-i18n.locales')
            ->assertSuccessful();
    }
}
