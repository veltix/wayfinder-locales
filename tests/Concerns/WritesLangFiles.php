<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests\Concerns;

use Illuminate\Filesystem\Filesystem;

trait WritesLangFiles
{
    public string $workspace;

    public string $output;

    /**
     * @param  array<string, array<string, string>>  $langFiles
     */
    public function setUpWorkspace(array $langFiles = []): void
    {
        $this->workspace = sys_get_temp_dir().'/wayfinder-locales-'.uniqid();
        $this->output = $this->workspace.'/js';

        $files = new Filesystem;

        foreach ($langFiles as $relativePath => $contents) {
            $path = $this->workspace.'/lang/'.$relativePath;
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, '<?php return '.var_export($contents, true).';');
        }
    }

    public function tearDownWorkspace(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);
    }

    public function generated(string $relativePath): string
    {
        return (new Filesystem)->get($this->output.'/'.$relativePath);
    }
}
