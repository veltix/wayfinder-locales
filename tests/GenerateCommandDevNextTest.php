<?php

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;

/**
 * Under dev-next the command must produce translations without touching the
 * action/route pipeline, which depends on `Laravel\Wayfinder\Route` — a class
 * the next branch does not ship.
 */
class GenerateCommandDevNextTest extends TestCase
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
        $files->put($this->workspace.'/lang/en/messages.php', '<?php return ["greeting" => "Hello :name"];');
        $files->put($this->workspace.'/lang/de/messages.php', '<?php return ["greeting" => "Hallo :name"];');

        // Excluded via wayfinder-i18n.lang_file, which only resolves if the
        // wayfinder-i18n config is merged on this path.
        $files->put($this->workspace.'/lang/en/routes.php', '<?php return ["search" => "search"];');
        $files->put($this->workspace.'/lang/de/routes.php', '<?php return ["search" => "suche"];');

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
    }

    /**
     * At least one route must exist, or the route pipeline never constructs a
     * Route wrapper and the bug hides behind an empty collection.
     */
    protected function defineRoutes($router): void
    {
        $router->get('/greet', fn () => 'hi')->name('greet');
    }

    #[Test]
    public function it_generates_translations_without_the_route_pipeline(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertFileExists($this->output.'/translations/en.ts');
        $this->assertFileExists($this->output.'/translations/de.ts');
        $this->assertStringContainsString('messages.greeting', file_get_contents($this->output.'/translations/en.ts'));
        $this->assertStringContainsString('Hallo :name', file_get_contents($this->output.'/translations/de.ts'));

        // The action/route pipeline is what would fatal on a real dev-next
        // install, where Laravel\Wayfinder\Route does not exist.
        $this->assertDirectoryDoesNotExist($this->output.'/actions');
        $this->assertDirectoryDoesNotExist($this->output.'/routes');
    }

    /**
     * The regression that a bare "hoist the command registration" fix would
     * have shipped: wayfinder-locales carries no `locales` key, so without the
     * wayfinder-i18n config the command falls back to its ['en'] default and a
     * bilingual app silently loses its second locale.
     */
    #[Test]
    public function it_emits_every_configured_locale_not_just_the_package_default(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertFileExists($this->output.'/translations/de.ts');
        $this->assertStringContainsString('Hallo :name', file_get_contents($this->output.'/translations/de.ts'));

        $index = file_get_contents($this->output.'/translations/index.ts');
        $this->assertStringContainsString("'de': () => import('./de')", $index);

        $locales = file_get_contents($this->output.'/wayfinder/locales.ts');
        $this->assertStringContainsString("export type Locale = 'en' | 'de';", $locales);
    }

    /**
     * lang_file/exclude_groups also live in wayfinder-i18n. Without the merge
     * the route-segment group leaks into the frontend catalogs.
     */
    #[Test]
    public function it_excludes_the_route_segment_group_from_the_catalogs(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringNotContainsString('routes.search', file_get_contents($this->output.'/translations/en.ts'));
        $this->assertStringContainsString('messages.greeting', file_get_contents($this->output.'/translations/en.ts'));
    }

    #[Test]
    public function it_announces_that_actions_and_routes_are_left_to_wayfinder(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->expectsOutputToContain('dev-next detected')
            ->assertSuccessful();
    }

    #[Test]
    public function it_does_not_clobber_the_wayfinder_helper_generated_by_dev_next(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->output.'/wayfinder');
        $files->put($this->output.'/wayfinder/index.ts', '// generated by laravel/wayfinder dev-next');

        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertSame(
            '// generated by laravel/wayfinder dev-next',
            $files->get($this->output.'/wayfinder/index.ts'),
        );
    }

    #[Test]
    public function it_emits_a_self_contained_locales_module_the_translation_runtime_can_import(): void
    {
        $this->artisan('wayfinder-i18n:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $locales = file_get_contents($this->output.'/wayfinder/locales.ts');

        $this->assertStringContainsString("export const defaultLocale: Locale = 'en';", $locales);
        $this->assertStringContainsString('export const getLocale', $locales);
        $this->assertStringContainsString('export const setLocale', $locales);

        $runtime = file_get_contents($this->output.'/translations/index.ts');

        $this->assertStringContainsString(
            'import { defaultLocale, getLocale, type Locale } from "../wayfinder/locales";',
            $runtime,
        );
        $this->assertStringNotContainsString('from "../wayfinder";', $runtime);
    }
}
