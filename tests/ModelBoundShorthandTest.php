<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Laravel\Ranger\RangerServiceProvider;
use Laravel\Surveyor\SurveyorServiceProvider;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\WayfinderServiceProvider;
use Symfony\Component\Process\Process;

use function Orchestra\Testbench\Pest\defineEnvironment;

final class ModelBoundShorthandPage implements UrlRoutable
{
    public function __construct(
        public readonly string $id = '',
        public readonly string $slug = '',
    ) {}

    public function getRouteKey(): string
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}

beforeEach(function (): void {
    $this->app->register(SurveyorServiceProvider::class);
    $this->app->register(RangerServiceProvider::class);
    $this->app->register(WayfinderServiceProvider::class);

    (new ReflectionProperty(ResultConverter::class, 'registry'))->setValue(null, null);

    $this->workspace = sys_get_temp_dir().'/wayfinder-locales-shorthand-'.uniqid();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->workspace);
});

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
    $app['config']->set('wayfinder-locales.hide_default_prefix', true);
});

function registerModelBoundShorthandRoute(Router $router): void
{
    $router->middleware(['setlocale', SubstituteBindings::class])
        ->get('/page/{page:slug}', fn (ModelBoundShorthandPage $page) => $page->id)
        ->name('page.show')
        ->localized(['en' => 'page', 'et' => 'leht']);

    $router->getRoutes()->refreshNameLookups();
}

function tsconfigForNodeExecution(string $directory): string
{
    $path = $directory.'/tsconfig.exec.json';

    (new Filesystem)->put($path, json_encode([
        'compilerOptions' => [
            'target' => 'ES2020',
            'module' => 'CommonJS',
            'moduleResolution' => 'Node10',
            'strict' => true,
            'esModuleInterop' => true,
            'skipLibCheck' => true,
            'outDir' => $directory.'/dist',
        ],
        'include' => [$directory.'/**/*.ts'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * @return array{0: bool, 1: string}
 */
function runGeneratedEntrypoint(string $directory, string $entrypoint): array
{
    $tsconfig = tsconfigForNodeExecution($directory);

    $compile = new Process([dirname(__DIR__).'/node_modules/.bin/tsc', '-p', $tsconfig]);
    $compile->setTimeout(60);
    $compile->run();

    if (! $compile->isSuccessful()) {
        return [false, $compile->getOutput().$compile->getErrorOutput()];
    }

    $run = new Process(['node', $directory.'/dist/'.$entrypoint]);
    $run->setTimeout(30);
    $run->run();

    if (! $run->isSuccessful()) {
        return [false, $run->getOutput().$run->getErrorOutput()];
    }

    return [true, $run->getOutput()];
}

it('preserves a sibling locale through the model-bound shorthand and returns the localized url, not the default one', function (): void {
    registerModelBoundShorthandRoute($this->app['router']);

    $this->artisan('wayfinder:generate', ['--path' => $this->workspace, '--fresh' => true])->assertSuccessful();

    expect($this->workspace.'/routes/page/index.ts')->toBeFile();

    $files = new Filesystem;

    $files->put($this->workspace.'/run.ts', <<<'TS'
        import { show } from "./routes/page";

        console.log(show.url({ slug: "the-product", locale: "et" }));
        TS);

    [$successful, $output] = runGeneratedEntrypoint($this->workspace, 'run.js');

    expect($successful)->toBeTrue($output);
    expect(trim($output))->toBe('/et/leht/the-product');
});

it('leaves the array-shorthand branch alone because a positional call has no sibling locale to lose', function (): void {
    registerModelBoundShorthandRoute($this->app['router']);

    $this->artisan('wayfinder:generate', ['--path' => $this->workspace, '--fresh' => true])->assertSuccessful();

    $emitted = (new Filesystem)->get($this->workspace.'/routes/page/index.ts');

    expect($emitted)->toContain('args = {
        page: args[0],
    }');

    $files = new Filesystem;

    $files->put($this->workspace.'/run.ts', <<<'TS'
        import { show } from "./routes/page";

        console.log(show.url(["the-product"]));
        TS);

    [$successful, $output] = runGeneratedEntrypoint($this->workspace, 'run.js');

    expect($successful)->toBeTrue($output);
    expect(trim($output))->toBe('/page/the-product');
});
