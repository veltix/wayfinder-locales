<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression guard for the stable line's generated output shape.
 */
class GenerateCommandStableTest extends TestCase
{
    protected bool $devNext = false;

    private string $workspace;

    private string $output;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/wayfinder-i18n-'.uniqid();
        $this->output = $this->workspace.'/js';

        $files = new Filesystem;
        $files->ensureDirectoryExists($this->workspace.'/lang/en');
        $files->put($this->workspace.'/lang/en/messages.php', '<?php return ["greeting" => "Hello :name"];');

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
        $app['config']->set('wayfinder-i18n.locales', ['en']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/greet', fn () => 'hi')->name('greet');
    }

    #[Test]
    public function it_writes_the_package_wayfinder_helper_and_translations(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringContainsString(
            'export const getLocale',
            file_get_contents($this->output.'/wayfinder/index.ts'),
        );

        // The locale store stays in index.ts on this line — duplicating it in
        // locales.ts would give the app two independent locale sources.
        $this->assertStringNotContainsString(
            'export const getLocale',
            file_get_contents($this->output.'/wayfinder/locales.ts'),
        );

        $runtime = file_get_contents($this->output.'/translations/index.ts');

        $this->assertStringContainsString('import { getLocale } from "../wayfinder";', $runtime);
        $this->assertStringContainsString('import { defaultLocale, type Locale } from "../wayfinder/locales";', $runtime);
        $this->assertStringContainsString('messages.greeting', file_get_contents($this->output.'/translations/en.ts'));

        // Route generation still runs on this line.
        $this->assertStringContainsString('greet', file_get_contents($this->output.'/routes/index.ts'));
    }
}
