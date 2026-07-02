<?php

namespace Veltix\WayfinderLocales;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as BaseRoute;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\View\Factory;
use Laravel\Wayfinder\Parameter;
use Laravel\Wayfinder\TypeScript;
use Veltix\WayfinderLocales\Translations\TranslationCollector;
use Veltix\WayfinderLocales\Translations\TranslationWriter;
use ReflectionProperty;

use function Illuminate\Filesystem\join_paths;
use function Laravel\Prompts\info;

class GenerateLocalizedCommand extends Command
{
    protected $signature = 'wayfinder-i18n:generate {--path=} {--skip-actions} {--skip-routes} {--with-form} {--skip-translations}';

    protected $description = 'Generate TypeScript representations of your Laravel actions and routes, with multilingual support.';

    private ?string $forcedScheme;

    private ?string $forcedRoot;

    private $urlDefaults = [];

    private $pathDirectory = 'actions';

    private $content = [];

    /**
     * Imports array where the key is the generated file path and the value is an array of imports.
     *
     * @var array<string, array<string, string[]>>
     */
    private $imports = [];

    public function __construct(
        private Filesystem $files,
        private Router $router,
        private Factory $view,
        private UrlGenerator $url,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->view->addNamespace('wayfinder-i18n', __DIR__.'/../resources');
        $this->view->addExtension('blade.ts', 'blade');

        $this->forcedScheme = (new ReflectionProperty($this->url, 'forceScheme'))->getValue($this->url);
        $this->forcedRoot = (new ReflectionProperty($this->url, 'forcedRoot'))->getValue($this->url);

        $globalUrlDefaults = collect(URL::getDefaultParameters())->map(fn ($v) => is_scalar($v) || is_null($v) ? $v : '');

        $routes = collect($this->router->getRoutes())->map(function (BaseRoute $route) use ($globalUrlDefaults) {
            $defaults = collect($this->router->gatherRouteMiddleware($route))->map(function ($middleware) {
                if ($middleware instanceof \Closure) {
                    return [];
                }

                $this->urlDefaults[$middleware] ??= $this->getDefaultsForMiddleware($middleware);

                return $this->urlDefaults[$middleware];
            })->flatMap(fn ($r) => $r);

            return new Route($route, $globalUrlDefaults->merge($defaults), $this->forcedScheme, $this->forcedRoot);
        });

        $this->writeWayfinderHelperFile();
        $this->writeLocalesFile();

        if (! $this->option('skip-actions')) {
            $controllers = $routes->filter(fn (Route $route) => $route->hasController())->groupBy(fn (Route $route) => $route->dotNamespace());

            $controllers->undot()->each($this->writeBarrelFiles(...));
            $controllers->each($this->writeControllerFile(...));

            $this->pruneStaleFiles($this->base(), $this->writeContent());

            info('[Wayfinder i18n] Generated actions in '.$this->base());
        }

        $this->pathDirectory = 'routes';

        if (! $this->option('skip-routes')) {
            $named = $routes->filter(fn (Route $route) => $route->name());

            [$localized, $plain] = $named->partition(fn (Route $route) => $this->isLocalized($route));

            $plainNamed = $plain->groupBy(fn (Route $route) => $route->name());
            $plainNamed->each($this->writeNamedFile(...));

            $localizedRoutes = $localized
                ->groupBy(fn (Route $route) => $this->baseName($route))
                ->map(fn (Collection $cluster, $base) => LocalizedRoute::fromCluster($base, $cluster, $this->defaultLocale()));

            $localizedRoutes->each(fn (LocalizedRoute $route) => $this->writeLocalizedNamedFile($route));

            $barrelSource = collect();
            $plainNamed->each(fn ($value, $key) => $barrelSource->offsetSet($key, $value));
            $localizedRoutes->each(fn ($value, $key) => $barrelSource->offsetSet($key, collect([$value])));
            $barrelSource->undot()->each($this->writeBarrelFiles(...));

            $this->pruneStaleFiles($this->base(), $this->writeContent());

            info('[Wayfinder i18n] Generated routes in '.$this->base());
        }

        if (! $this->option('skip-translations')) {
            $path = $this->writeTranslations();

            info('[Wayfinder i18n] Generated translations in '.$path);
        }
    }

    private function locales(): array
    {
        return array_values((array) config('wayfinder-i18n.locales', ['en']));
    }

    private function defaultLocale(): string
    {
        return (string) config('wayfinder-i18n.default', 'en');
    }

    private function isLocalized(Route $route): bool
    {
        if ($route->localeMarker() !== null) {
            return true;
        }

        $name = $route->rawName();

        return $name !== null && in_array(Str::before($name, '.'), $this->locales(), true);
    }

    private function baseName(Route $route): string
    {
        $name = (string) $route->rawName();
        $locale = $route->localeMarker() ?? Str::before($name, '.');

        return Str::after($name, $locale.'.');
    }

    private function writeWayfinderHelperFile(): void
    {
        $previousPathDirectory = $this->pathDirectory;
        $this->pathDirectory = 'wayfinder';

        $this->files->ensureDirectoryExists($this->base());

        $source = __DIR__.'/../resources/js/wayfinder.ts';
        $destination = join_paths($this->base(), 'index.ts');

        $this->writeContentIfChanged($destination, $this->files->get($source));

        $this->pathDirectory = $previousPathDirectory;
    }

    private function writeLocalesFile(): void
    {
        $previousPathDirectory = $this->pathDirectory;
        $this->pathDirectory = 'wayfinder';

        $this->files->ensureDirectoryExists($this->base());

        $locales = $this->locales();
        $default = $this->defaultLocale();

        $union = $locales === []
            ? 'string'
            : collect($locales)->map(fn ($l) => (string) Js::from($l))->implode(' | ');

        $list = collect($locales)->map(fn ($l) => (string) Js::from($l))->implode(', ');
        $defaultValue = (string) Js::from($default);

        $content = <<<TS
        export type Locale = {$union};

        export const locales: Locale[] = [{$list}];

        export const defaultLocale: Locale = {$defaultValue};

        TS;

        $this->writeContentIfChanged(join_paths($this->base(), 'locales.ts'), $content);

        $this->pathDirectory = $previousPathDirectory;
    }

    private function writeTranslations(): string
    {
        $previousPathDirectory = $this->pathDirectory;
        $this->pathDirectory = 'translations';

        $excludeGroups = array_values(array_unique(array_merge(
            [(string) config('wayfinder-i18n.lang_file', 'routes')],
            (array) config('wayfinder-i18n.exclude_groups', []),
        )));

        $collector = new TranslationCollector($this->files, lang_path());
        $collected = $collector->collect($this->locales(), $this->defaultLocale(), $excludeGroups);

        $base = $this->base();

        (new TranslationWriter($this->files))->write($base, $collected, $this->locales(), $this->defaultLocale());

        $this->pathDirectory = $previousPathDirectory;

        return $base;
    }

    private function appendContent(string $path, string $content): void
    {
        $this->content[$path] ??= [];

        if (! in_array($content, $this->content[$path])) {
            $this->content[$path][] = $content;
        }
    }

    private function prependContent(string $path, string $content): void
    {
        $this->content[$path] ??= [];

        array_unshift($this->content[$path], $content);
    }

    /**
     * @return string[] paths that were written
     */
    private function writeContent(): array
    {
        $written = [];

        foreach ($this->content as $path => $content) {
            $this->files->ensureDirectoryExists(dirname($path));

            $body = TypeScript::cleanUp(implode(PHP_EOL, $content));

            if (isset($this->imports[$path])) {
                $importLines = collect($this->imports[$path])
                    ->map(fn ($imports, $key) => 'import { '.implode(', ', array_unique($imports))." } from '{$key}'")
                    ->implode(PHP_EOL);

                $body = $importLines.PHP_EOL.$body;
            }

            $this->writeContentIfChanged($path, $body);

            $written[] = $path;
        }

        $this->content = [];
        $this->imports = [];

        return $written;
    }

    private function writeContentIfChanged(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));

        if (! $this->files->exists($path) || $this->files->get($path) !== $content) {
            $this->files->put($path, $content);
        }
    }

    private function pruneStaleFiles(string $base, array $writtenPaths): void
    {
        if (! $this->files->isDirectory($base)) {
            return;
        }

        $kept = collect($writtenPaths)->map(fn ($path) => realpath($path) ?: $path)->flip();

        foreach ($this->files->allFiles($base) as $file) {
            $path = $file->getPathname();

            if (! $kept->has(realpath($path) ?: $path)) {
                $this->files->delete($path);
            }
        }

        $this->pruneEmptyDirectories($base);
    }

    private function pruneEmptyDirectories(string $dir): void
    {
        if (! $this->files->isDirectory($dir)) {
            return;
        }

        foreach ($this->files->directories($dir) as $sub) {
            $this->pruneEmptyDirectories($sub);
        }

        if (empty($this->files->files($dir)) && empty($this->files->directories($dir))) {
            $this->files->deleteDirectory($dir);
        }
    }

    private function writeControllerFile(Collection $routes, string $namespace): void
    {
        $path = join_paths($this->base(), ...explode('.', $namespace)).'.ts';

        $hasLocalized = $routes->contains(fn (Route $route) => $this->isLocalized($route));

        $this->appendCommonImports($routes, $path, $namespace, $hasLocalized);

        $routes->groupBy(fn (Route $route) => $route->method())->each(function (Collection $methodRoutes) use ($path) {
            $localizedClusters = $methodRoutes
                ->filter(fn (Route $route) => $this->isLocalized($route))
                ->groupBy(fn (Route $route) => $this->baseName($route))
                ->map(fn (Collection $cluster, $base) => LocalizedRoute::fromCluster($base, $cluster, $this->defaultLocale()))
                ->values();

            $plain = $methodRoutes->reject(fn (Route $route) => $this->isLocalized($route))->values();

            // A controller method can serve several distinct routes (e.g. an
            // index that also handles a {param} variant). One logical route →
            // a single callable; many → a URI-keyed dictionary (upstream shape).
            if ($localizedClusters->count() + $plain->count() === 1) {
                if ($localizedClusters->isNotEmpty()) {
                    $this->writeLocalizedMethodExport($localizedClusters->first(), $path);
                } else {
                    $this->writeControllerMethodExport($plain->first(), $path);
                }

                return;
            }

            if ($localizedClusters->isEmpty()) {
                $this->writeMultiRouteControllerMethodExport($plain, $path);

                return;
            }

            $this->writeMixedMethodExport($localizedClusters, $plain, $path);
        });

        [$invokable, $methods] = $routes->partition(fn (Route $route) => $route->hasInvokableController());

        $defaultExport = $invokable->isNotEmpty() ? $invokable->first()->jsMethod() : last(explode('.', $namespace));

        if ($invokable->isEmpty()) {
            $exportedMethods = $methods->map(fn (Route $route) => $route->jsMethod());
            $reservedMethods = $methods->filter(fn (Route $route) => $route->originalJsMethod() !== $route->jsMethod())->map(fn (Route $route) => TypeScript::quoteIfNeeded($route->originalJsMethod()).': '.$route->jsMethod());
            $exportedMethods = $exportedMethods->merge($reservedMethods);

            $methodProps = "const {$defaultExport} = { ";
            $methodProps .= $exportedMethods->unique()->implode(', ');
            $methodProps .= ' }';
        } else {
            $methodProps = $methods->map(fn (Route $route) => $defaultExport.'.'.$route->jsMethod().' = '.$route->jsMethod())->unique()->implode(PHP_EOL);
        }

        $this->appendContent($path, <<<JAVASCRIPT
        {$methodProps}

        export default {$defaultExport}
        JAVASCRIPT);
    }

    private function safeParamNames(string $method): array
    {
        $reserved = [
            'args' => 'routeArgs',
            'options' => 'routeOptions',
            'parsedArgs' => 'routeParsedArgs',
        ];

        $params = array_map(fn ($default, $name) => $method === $name ? $default : $name, $reserved, array_keys($reserved));

        return array_combine(array_keys($reserved), $params);
    }

    private function writeMultiRouteControllerMethodExport(Collection $routes, string $path): void
    {
        $isInvokable = $routes->first()->hasInvokableController();
        $method = $routes->first()->jsMethod();

        $this->appendContent($path, $this->view->make('wayfinder-i18n::multi-method', [
            'method' => $method,
            'original_method' => $routes->first()->originalJsMethod(),
            'path' => $routes->first()->controllerPath(),
            'line' => $routes->first()->controllerMethodLineNumber(),
            'controller' => $routes->first()->controller(),
            'isInvokable' => $isInvokable,
            'shouldExport' => ! $isInvokable,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
            'routes' => $routes->map(fn ($r) => [
                'method' => $r->jsMethod(),
                'tempMethod' => $r->jsMethod().hash('xxh128', $r->uri()),
                'parameters' => $r->parameters(),
                'verbs' => $r->verbs(),
                'uri' => $r->uri(),
            ]),
        ])->render());
    }

    /**
     * Emit a URI-keyed dictionary for a controller method that serves several
     * distinct routes, where one or more of them are localized.
     *
     * @param  Collection<int, LocalizedRoute>  $localizedClusters
     * @param  Collection<int, Route>  $plain
     */
    private function writeMixedMethodExport(Collection $localizedClusters, Collection $plain, string $path): void
    {
        $reference = $localizedClusters->first()?->canonical ?? $plain->first();
        $method = $reference->jsMethod();

        $routes = collect();

        foreach ($localizedClusters as $cluster) {
            $route = $cluster->canonical;

            $routes->push([
                'method' => $route->jsMethod(),
                'tempMethod' => $route->jsMethod().hash('xxh128', $cluster->baseName),
                'parameters' => $route->parameters(),
                'verbs' => $route->verbs(),
                'uri' => $route->uri(),
                'localized' => true,
                'localesToUri' => $cluster->localesToUri,
                'defaultLocale' => $cluster->defaultLocale,
            ]);
        }

        foreach ($plain as $route) {
            $routes->push([
                'method' => $route->jsMethod(),
                'tempMethod' => $route->jsMethod().hash('xxh128', $route->uri()),
                'parameters' => $route->parameters(),
                'verbs' => $route->verbs(),
                'uri' => $route->uri(),
                'localized' => false,
                'localesToUri' => [],
                'defaultLocale' => '',
            ]);
        }

        $this->appendContent($path, $this->view->make('wayfinder-i18n::multi-method', [
            'method' => $method,
            'original_method' => $reference->originalJsMethod(),
            'path' => $reference->controllerPath(),
            'line' => $reference->controllerMethodLineNumber(),
            'controller' => $reference->controller(),
            'isInvokable' => $reference->hasInvokableController(),
            'shouldExport' => ! $reference->hasInvokableController(),
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
            'routes' => $routes,
        ])->render());
    }

    private function writeControllerMethodExport(Route $route, string $path): void
    {
        $method = $route->jsMethod();

        $this->appendContent($path, $this->view->make('wayfinder-i18n::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => ! $route->hasInvokableController(),
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'localized' => false,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    private function writeLocalizedMethodExport(LocalizedRoute $localized, string $path): void
    {
        $route = $localized->canonical;
        $method = $route->jsMethod();

        $this->appendContent($path, $this->view->make('wayfinder-i18n::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => ! $route->hasInvokableController(),
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'localized' => true,
            'localesToUri' => $localized->localesToUri,
            'defaultLocale' => $localized->defaultLocale,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    private function writeNamedFile(Collection $routes, string $namespace): void
    {
        $parts = explode('.', $namespace);
        array_pop($parts);
        $parts[] = 'index';

        $path = join_paths($this->base(), ...$parts).'.ts';

        $this->appendCommonImports($routes, $path, $namespace);

        $routes->each(fn (Route $route) => $this->writeNamedMethodExport($route, $path));
    }

    private function writeLocalizedNamedFile(LocalizedRoute $localized): void
    {
        $namespace = $localized->baseName;

        $parts = explode('.', $namespace);
        array_pop($parts);
        $parts[] = 'index';

        $path = join_paths($this->base(), ...$parts).'.ts';

        $this->appendCommonImports(collect([$localized->canonical]), $path, $namespace, true);

        $route = $localized->canonical;
        $method = $route->namedMethod();

        $this->appendContent($path, $this->view->make('wayfinder-i18n::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => true,
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'localized' => true,
            'localesToUri' => $localized->localesToUri,
            'defaultLocale' => $localized->defaultLocale,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    private function appendCommonImports(Collection $routes, string $path, string $namespace, bool $hasLocalized = false): void
    {
        $imports = ['queryParams', 'type RouteQueryOptions', 'type RouteDefinition'];

        if ($this->option('with-form') === true) {
            $imports[] = 'type RouteFormDefinition';
        }

        if ($routes->contains(fn (Route $route) => $route->parameters()->isNotEmpty())) {
            $imports[] = 'applyUrlDefaults';
        }

        if ($routes->contains(fn (Route $route) => $route->parameters()->contains(fn (Parameter $parameter) => $parameter->optional))) {
            $imports[] = 'validateParameters';
        }

        if ($hasLocalized) {
            $imports[] = 'resolveLocalizedUrl';
            $imports[] = 'type LocalizedRouteDefinition';
        }

        $importBase = str_repeat('/..', substr_count($namespace, '.') + 1);
        $pathKey = ".{$importBase}/wayfinder";

        $this->imports[$path] ??= [];
        $this->imports[$path][$pathKey] = [
            ...($this->imports[$path][$pathKey] ?? []),
            ...$imports,
        ];
    }

    private function writeNamedMethodExport(Route $route, string $path): void
    {
        $method = $route->namedMethod();

        $this->appendContent($path, $this->view->make('wayfinder-i18n::method', [
            'controller' => $route->controller(),
            'method' => $method,
            'original_method' => $route->originalJsMethod(),
            'isInvokable' => $route->hasInvokableController(),
            'shouldExport' => true,
            'path' => $route->controllerPath(),
            'line' => $route->controllerMethodLineNumber(),
            'parameters' => $route->parameters(),
            'verbs' => $route->verbs(),
            'uri' => $route->uri(),
            'localized' => false,
            'withForm' => $this->option('with-form') ?? false,
            ...$this->safeParamNames($method),
        ])->render());
    }

    private function writeBarrelFiles(array|Collection $children, string $parent): void
    {
        $children = collect($children);

        if (array_is_list($children->all())) {
            return;
        }

        $indexPath = join_paths($this->base(), $parent, 'index.ts');
        $keysWithGrandkids = $children->filter(fn ($grandChildren) => ! array_is_list(collect($grandChildren)->all()));

        $childKeys = $children->keys()->mapWithKeys(function ($child) use ($indexPath, $keysWithGrandkids) {
            $safeMethod = TypeScript::safeMethod($child, 'Method');
            $safe = $safeMethod;

            if ($keysWithGrandkids->has($child)) {
                foreach ($this->content[$indexPath] ?? [] as $content) {
                    if (str_contains((string) $content, 'const '.$safeMethod.' =')) {
                        $safe .= str(hash('xxh128', $safe))->substr(0, 6)->ucfirst();
                    }
                }
            }

            return [
                $child => [
                    'safeMethod' => $safeMethod,
                    'safe' => $safe,
                    'safeAssign' => "Object.assign({$safeMethod}, {$safe})",
                    'normalized' => str($child)->whenContains('-', fn ($s) => $s->camel())->toString(),
                ],
            ];
        });

        if (! ($this->content[$indexPath] ?? false)) {
            $imports = $childKeys->filter(fn ($_, $key) => $key !== 'index')->map(fn ($alias, $key) => "import {$alias['safe']} from './{$key}'")->implode(PHP_EOL);
        } else {
            $imports = $childKeys->only($keysWithGrandkids->keys())->map(fn ($alias, $key) => "import {$alias['safe']} from './{$key}'")->implode(PHP_EOL);
        }

        if ($imports) {
            $this->prependContent($indexPath, $imports);
        }

        $keys = $childKeys->map(fn ($alias, $key) => str_repeat(' ', 4).implode(': ', array_unique([$alias['normalized'], $alias['safeAssign'] ?? $alias['safe']])))->implode(', '.PHP_EOL);

        $varExport = TypeScript::safeMethod(Str::afterLast($parent, DIRECTORY_SEPARATOR), 'Method');
        $existingVars = $childKeys
            ->flatMap(fn ($alias) => [$alias['safeMethod'], $alias['safe']])
            ->filter()
            ->unique()
            ->values();

        if ($existingVars->contains($varExport)) {
            $baseExport = $varExport.'Namespace';
            $varExport = TypeScript::safeMethod($baseExport, 'Method');
            $suffix = 2;

            while ($existingVars->contains($varExport)) {
                $varExport = TypeScript::safeMethod($baseExport.$suffix, 'Method');
                $suffix++;
            }
        }

        $this->appendContent($indexPath, <<<JAVASCRIPT


                const {$varExport} = {
                {$keys},
                }

                export default {$varExport}
                JAVASCRIPT);

        $children->each(fn ($grandChildren, $child) => $this->writeBarrelFiles($grandChildren, join_paths($parent, $child)));
    }

    private function base(): string
    {
        $base = $this->option('path') ?? join_paths(resource_path(), 'js');

        return join_paths($base, $this->pathDirectory);
    }

    private function getDefaultsForMiddleware(string $middleware)
    {
        if (! class_exists($middleware)) {
            return [];
        }

        $reflection = new \ReflectionClass($middleware);

        if (! $reflection->hasMethod('handle')) {
            return [];
        }

        $method = $reflection->getMethod('handle');

        $fileName = $method->getFileName();
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        $lines = file($fileName);
        $methodContents = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        if (! str_contains($methodContents, 'URL::defaults')) {
            return [];
        }

        $methodContents = str($methodContents)->after('{')->beforeLast('}')->trim();

        return $this->extractUrlDefaults($methodContents);
    }

    private function extractUrlDefaults(string $methodContents): array
    {
        $tokens = token_get_all('<?php '.$methodContents);
        $foundUrlFacade = false;
        $defaults = [];
        $inArray = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && token_name($token[0]) === 'T_STRING') {
                if (
                    $token[1] === 'URL'
                    && is_array($tokens[$index + 1])
                    && $tokens[$index + 1][1] === '::'
                    && is_array($tokens[$index + 2])
                    && $tokens[$index + 2][1] === 'defaults'
                ) {
                    $foundUrlFacade = true;
                }
            }

            if (! $foundUrlFacade) {
                continue;
            }

            if ((is_array($token) && $token[0] === T_ARRAY) || $token === '[') {
                $inArray = true;
            }

            if (! $inArray) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                $count = 1;
                $previousToken = $tokens[$index - $count];

                while (is_array($previousToken) && $previousToken[0] === T_WHITESPACE) {
                    $count++;
                    $previousToken = $tokens[$index - $count];
                }

                $valueToken = $tokens[$index + 1];
                $count = 1;

                while (is_array($valueToken) && $valueToken[0] === T_WHITESPACE) {
                    $count++;
                    $valueToken = $tokens[$index + $count];
                }

                $value = trim($valueToken[1], "'\"");

                $value = match ($value) {
                    'true' => 1,
                    'false' => 0,
                    default => $value,
                };

                $defaults[trim($previousToken[1], "'\"")] = $value;
            }

            if ($token === ']') {
                $inArray = false;
                break;
            }
        }

        return $defaults;
    }
}
