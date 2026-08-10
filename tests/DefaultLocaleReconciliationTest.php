<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two lines keep their default locale in different config files. Under
 * dev-next the route-side value (`wayfinder-locales.default_locale`, which
 * route generation already reads) wins, so an app declares it once.
 */
class DefaultLocaleReconciliationTest extends TestCase
{
    protected bool $devNext = true;

    private string $workspace;

    private string $output;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/wayfinder-i18n-'.uniqid();
        $this->output = $this->workspace.'/js';

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->workspace.'/lang/en');
        $files->ensureDirectoryExists($this->workspace.'/lang/de');
        $files->put($this->workspace.'/lang/en/messages.php', '<?php return ["only_en" => "en"];');
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
        $app['config']->set('wayfinder-i18n.locales', ['en', 'de']);
        $app['config']->set('wayfinder-i18n.default', 'en');
        $app['config']->set('wayfinder-locales.default_locale', 'de');
    }

    #[Test]
    public function the_route_side_default_locale_drives_translation_generation_under_dev_next(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "export const defaultLocale: Locale = 'de';",
            file_get_contents($this->output.'/wayfinder/locales.ts'),
        );

        // The default locale's catalog seeds the key union.
        $keys = file_get_contents($this->output.'/translations/keys.ts');

        $this->assertStringContainsString('messages.only_de', $keys);
        $this->assertStringNotContainsString('messages.only_en', $keys);
    }
}
