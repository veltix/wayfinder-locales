<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use Veltix\WayfinderLocales\Tests\Concerns\WritesLangFiles;

class GenerateCommandTest extends TestCase
{
    use WritesLangFiles;

    protected function setUp(): void
    {
        $this->setUpWorkspace();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorkspace();

        parent::tearDown();
    }

    protected function langFiles(): array
    {
        return [
            'en/messages.php' => ['greeting' => 'Hello :name', 'nested' => ['deep' => 'Deep']],
            'de/messages.php' => ['greeting' => 'Hallo :name', 'nested' => ['deep' => 'Tief']],
            'en/routes.php' => ['search' => 'search'],
            'de/routes.php' => ['search' => 'suche'],
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app->useLangPath($this->workspace.'/lang');
        $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
        $app['config']->set('wayfinder-locales.default_locale', 'en');
    }

    /**
     * At least one route must exist or the old command's route-wrapping closure
     * never ran and the whole failure hid behind an empty collection — a bare
     * Testbench app registers none, so this test would pass vacuously without it.
     */
    protected function defineRoutes($router): void
    {
        $router->get('/greet', fn () => 'hi')->name('greet');
    }

    /**
     * THE regression test. `locales` lived in a config the dev-next provider
     * never merged, so the command fell through to the package default `['en']`
     * and a bilingual app silently generated English only. Against the
     * pre-rewrite code this fails on the missing de.ts.
     */
    #[Test]
    public function a_two_locale_app_emits_both_catalogs(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertFileExists($this->output.'/translations/en.ts');
        $this->assertFileExists($this->output.'/translations/de.ts');

        $this->assertStringContainsString('Hello :name', $this->generated('translations/en.ts'));
        $this->assertStringContainsString('Hallo :name', $this->generated('translations/de.ts'));

        $index = $this->generated('translations/index.ts');
        $this->assertStringContainsString("'en': () => import('./en')", $index);
        $this->assertStringContainsString("'de': () => import('./de')", $index);

        $this->assertStringContainsString(
            "export type Locale = 'en' | 'de';",
            $this->generated('translations/locales.ts'),
        );
    }

    #[Test]
    public function it_flattens_lang_group_files_into_dotted_catalog_keys(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $en = $this->generated('translations/en.ts');

        $this->assertStringContainsString('"messages.greeting"', $en);
        $this->assertStringContainsString('"messages.nested.deep"', $en);

        $keys = $this->generated('translations/keys.ts');

        $this->assertStringContainsString("'messages.greeting'", $keys);
        $this->assertStringContainsString("'messages.nested.deep'", $keys);
    }

    #[Test]
    public function it_types_the_placeholders_of_each_key(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringContainsString(
            "'messages.greeting': { name: string | number };",
            $this->generated('translations/keys.ts'),
        );
    }

    #[Test]
    public function it_excludes_the_configured_lang_groups(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertStringNotContainsString('routes.search', $this->generated('translations/en.ts'));
        $this->assertStringContainsString('messages.greeting', $this->generated('translations/en.ts'));
    }

    /**
     * Actions and named routes are `wayfinder:generate`'s output. Writing them
     * from here is what fataled on dev-next, and it would fight Wayfinder's own
     * prune if it did not.
     */
    #[Test]
    public function it_does_not_generate_actions_or_routes(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->output.'/actions');
        $this->assertDirectoryDoesNotExist($this->output.'/routes');
    }

    /**
     * `wayfinder:generate` owns `resources/js/wayfinder` and deletes anything
     * there it did not write, so the package must keep out of it entirely.
     */
    #[Test]
    public function it_writes_nothing_into_wayfinders_own_output_directory(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->output.'/wayfinder');
        $files->put($this->output.'/wayfinder/index.ts', '// generated by laravel/wayfinder');

        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertSame(
            ['index.ts'],
            array_map(fn ($file) => $file->getFilename(), $files->files($this->output.'/wayfinder')),
        );
        $this->assertSame('// generated by laravel/wayfinder', $files->get($this->output.'/wayfinder/index.ts'));
    }

    /**
     * On dev-next `wayfinder/index.ts` is Wayfinder's and exports no locale
     * accessors, so the runtime has to carry its own store.
     */
    #[Test]
    public function the_generated_runtime_is_self_contained(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $locales = $this->generated('translations/locales.ts');

        $this->assertStringContainsString('export const setLocale', $locales);
        $this->assertStringContainsString('export const getLocale', $locales);
        $this->assertStringContainsString("export const locales: Locale[] = ['en', 'de'];", $locales);

        $runtime = $this->generated('translations/index.ts');

        $this->assertStringContainsString(
            'import { defaultLocale, getLocale, type Locale } from "./locales";',
            $runtime,
        );
        $this->assertStringNotContainsString('../wayfinder', $runtime);
    }

    #[Test]
    public function it_prunes_catalogs_for_locales_that_are_no_longer_configured(): void
    {
        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertFileExists($this->output.'/translations/de.ts');

        config()->set('wayfinder-locales.locales', ['en']);

        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->output.'/translations/de.ts');
        $this->assertFileExists($this->output.'/translations/en.ts');
    }

    #[Test]
    public function it_fails_when_no_locales_are_configured(): void
    {
        config()->set('wayfinder-locales.locales', []);

        $this->artisan('wayfinder-locales:generate', ['--path' => $this->output])
            ->expectsOutputToContain('No locales configured')
            ->assertFailed();
    }
}
