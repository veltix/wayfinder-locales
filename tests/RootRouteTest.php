<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use Laravel\Ranger\RangerServiceProvider;
use Laravel\Surveyor\SurveyorServiceProvider;
use Laravel\Wayfinder\Registry\ResultConverter;
use Laravel\Wayfinder\WayfinderServiceProvider;
use Symfony\Component\Process\Process;

use function Orchestra\Testbench\Pest\defineEnvironment;

beforeEach(function (): void {
    $this->app->register(SurveyorServiceProvider::class);
    $this->app->register(RangerServiceProvider::class);
    $this->app->register(WayfinderServiceProvider::class);

    (new ReflectionProperty(ResultConverter::class, 'registry'))->setValue(null, null);

    $this->workspace = sys_get_temp_dir().'/wayfinder-locales-root-route-'.uniqid();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->workspace);
});

defineEnvironment(function (Application $app): void {
    $app['config']->set('wayfinder-locales.locales', ['en', 'et']);
    $app['config']->set('wayfinder-locales.default_locale', 'en');
});

function registerRootRoute(Router $router): void
{
    $router->middleware('setlocale')
        ->get('/', fn () => 'home:'.app()->getLocale())
        ->name('home')
        ->localized(['en' => '', 'et' => '']);

    $router->getRoutes()->refreshNameLookups();
}

function tsconfigForRootRouteExecution(string $directory): string
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
function runGeneratedRootRouteEntrypoint(string $directory, string $entrypoint): array
{
    $tsconfig = tsconfigForRootRouteExecution($directory);

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

it('registers only the non-default locale twin for a root route', function (): void {
    registerRootRoute($this->app['router']);

    /** @var Router $router */
    $router = $this->app['router'];

    $base = $router->getRoutes()->getByName('home');
    expect($base)->not->toBeNull();
    expect($base->uri())->toBe('/');

    $twin = $router->getRoutes()->getByName('home.locale.et');
    expect($twin)->not->toBeNull();
    expect($twin->uri())->toBe('et');

    expect($router->getRoutes()->getByName('home.locale.en'))->toBeNull();
    expect($router->getRoutes()->getByName('home.default'))->toBeNull();
});

it('resolves both locales of a root route inbound and sets the application locale', function (): void {
    registerRootRoute($this->app['router']);

    $default = $this->get('/');
    $default->assertOk();
    expect($default->getContent())->toBe('home:en');
    expect(app()->getLocale())->toBe('en');

    $translated = $this->get('/et');
    $translated->assertOk();
    expect($translated->getContent())->toBe('home:et');
    expect(app()->getLocale())->toBe('et');
});

it('resolves a root route outbound through lroute for both locales', function (): void {
    registerRootRoute($this->app['router']);

    expect(lroute('home', [], 'en', absolute: false))->toBe('/');
    expect(lroute('home', [], 'et', absolute: false))->toBe('/et');
});

it('rejects a non-empty translation value on a root route in strict mode', function (): void {
    /** @var Router $router */
    $router = $this->app['router'];

    expect(function () use ($router): void {
        $router->middleware('setlocale')
            ->get('/', fn () => 'home')
            ->name('home')
            ->localized(['en' => '', 'et' => 'avaleht']);
    })->toThrow(InvalidArgumentException::class);
});

it('drops a non-empty translation value on a root route when not strict', function (): void {
    config()->set('wayfinder-locales.strict', false);

    /** @var Router $router */
    $router = $this->app['router'];

    $router->middleware('setlocale')
        ->get('/', fn () => 'home:'.app()->getLocale())
        ->name('home')
        ->localized(['en' => '', 'et' => 'avaleht']);

    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('home.locale.et'))->toBeNull();

    $default = $this->get('/');
    $default->assertOk();
    expect($default->getContent())->toBe('home:en');
});

it('type-checks the generated root route and returns the localized url, not the default one', function (): void {
    registerRootRoute($this->app['router']);

    $this->artisan('wayfinder:generate', ['--path' => $this->workspace, '--fresh' => true])->assertSuccessful();

    expect($this->workspace.'/routes/index.ts')->toBeFile();

    $files = new Filesystem;

    $files->put($this->workspace.'/run.ts', <<<'TS'
        import { home } from "./routes";

        console.log(home.url({ locale: "et" }));
        console.log(home.url({ locale: "en" }));
        console.log(home.url());
        TS);

    [$successful, $output] = runGeneratedRootRouteEntrypoint($this->workspace, 'run.js');

    expect($successful)->toBeTrue($output);

    $lines = explode(PHP_EOL, trim($output));

    expect($lines)->toBe(['/et', '/', '/']);
});
