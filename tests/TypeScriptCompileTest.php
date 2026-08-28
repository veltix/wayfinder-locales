<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Laravel\Ranger\RangerServiceProvider;
use Laravel\Surveyor\SurveyorServiceProvider;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\WayfinderServiceProvider;
use Symfony\Component\Process\Process;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Orchestra\Testbench\Pest\defineRoutes;

beforeEach(function (): void {
    $this->app->register(SurveyorServiceProvider::class);
    $this->app->register(RangerServiceProvider::class);
    $this->app->register(WayfinderServiceProvider::class);

    (new ReflectionProperty(ResultConverter::class, 'registry'))->setValue(null, null);

    $this->tscWorkspace = sys_get_temp_dir().'/wayfinder-locales-tsc-'.uniqid();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->tscWorkspace);
});

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'de']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

defineRoutes(function (Router $router): void {
    $router->get('/{locale}/products/{product}', fn () => 'ok')
        ->name('products')
        ->localized(['en' => 'products', 'de' => 'produkte']);

    $router->get('/{locale?}/about', fn () => 'ok')
        ->name('about')
        ->localized(['en' => 'about', 'de' => 'ueber-uns']);

    $router->get('/status', fn () => 'ok')->name('status');

    $router->get('/product/{product}', fn () => 'ok')
        ->name('product.show')
        ->localized(['en' => 'product', 'de' => 'produkt']);

    $router->get('/catalog', fn () => 'ok')
        ->name('catalog.listing')
        ->localized(['en' => 'catalog', 'de' => 'katalog']);
});

function tscBinaryPath(): string
{
    return dirname(__DIR__).'/node_modules/.bin/tsc';
}

/**
 * @return array{0: bool, 1: string}
 */
function compileGeneratedTypeScript(string $directory): array
{
    $files = new Filesystem;

    $files->put($directory.'/tsconfig.json', json_encode([
        'compilerOptions' => [
            'target' => 'ES2020',
            'module' => 'ESNext',
            'moduleResolution' => 'Bundler',
            'strict' => true,
            'noUnusedLocals' => true,
            'noUnusedParameters' => true,
            'esModuleInterop' => true,
            'isolatedModules' => true,
            'forceConsistentCasingInFileNames' => true,
            'skipLibCheck' => true,
            'noEmit' => true,
        ],
        'include' => ['**/*.ts'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $process = new Process([tscBinaryPath(), '-p', $directory.'/tsconfig.json']);
    $process->setTimeout(60);
    $process->run();

    return [$process->isSuccessful(), $process->getOutput().$process->getErrorOutput()];
}

it('pins the TypeScript narrowing gap the optional-locale index-lookup fallback works around', function (): void {
    $files = new Filesystem;
    $snippet = sys_get_temp_dir().'/wayfinder-locales-narrowing-repro-'.uniqid().'.ts';

    $files->put($snippet, <<<'TS'
        type Args = { locale?: "en" | "de" } | undefined;
        const templates = { en: "a", de: "b" } as const;
        function url(args: Args) {
            if (args?.locale === undefined) { args = { ...(args ?? {}), locale: "en" } }
            const parsedArgs = { locale: args?.locale };
            return templates[parsedArgs.locale];
        }
        TS);

    $process = new Process([tscBinaryPath(), '--strict', '--noEmit', '--noUnusedLocals', '--noUnusedParameters', $snippet]);
    $process->run();

    $files->delete($snippet);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getOutput().$process->getErrorOutput())
        ->toContain('TS2538')
        ->toContain("Type 'undefined' cannot be used as an index type.");
});

it('confirms a nullish-coalescing fallback at the index site is what resolves the narrowing gap', function (): void {
    $files = new Filesystem;
    $snippet = sys_get_temp_dir().'/wayfinder-locales-narrowing-fix-'.uniqid().'.ts';

    $files->put($snippet, <<<'TS'
        type Args = { locale?: "en" | "de" } | undefined;
        const templates = { en: "a", de: "b" } as const;
        function url(args: Args) {
            if (args?.locale === undefined) { args = { ...(args ?? {}), locale: "en" } }
            const parsedArgs = { locale: args?.locale ?? "en" };
            return templates[parsedArgs.locale];
        }
        TS);

    $process = new Process([tscBinaryPath(), '--strict', '--noEmit', '--noUnusedLocals', '--noUnusedParameters', $snippet]);
    $process->run();

    $files->delete($snippet);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});

it('has a tsc binary available for the compile check', function (): void {
    expect(is_executable(tscBinaryPath()))->toBeTrue(
        'Run `bun install` (or `npm install`) at the package root so tsc is available for the compile check.',
    );
});

it('type-checks the real wayfinder:generate output for every localized route declaration form', function (): void {
    $this->artisan('wayfinder:generate', ['--path' => $this->tscWorkspace, '--fresh' => true])
        ->assertSuccessful();

    expect($this->tscWorkspace.'/routes/index.ts')->toBeFile();
    expect($this->tscWorkspace.'/routes/product/index.ts')->toBeFile();
    expect($this->tscWorkspace.'/routes/catalog/index.ts')->toBeFile();

    [$successful, $output] = compileGeneratedTypeScript($this->tscWorkspace);

    expect($successful)->toBeTrue($output);
});
