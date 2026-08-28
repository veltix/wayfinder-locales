<?php

declare(strict_types=1);

namespace Veltix\WayfinderLocales\Tests\Concerns;

use Illuminate\Filesystem\Filesystem;

trait WritesLangFiles
{
    protected string $workspace;

    protected string $output;

    protected function setUpWorkspace(): void
    {
        $this->workspace = sys_get_temp_dir().'/wayfinder-locales-'.uniqid();
        $this->output = $this->workspace.'/js';

        $files = new Filesystem;

        foreach ($this->langFiles() as $relativePath => $contents) {
            $path = $this->workspace.'/lang/'.$relativePath;
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, '<?php return '.var_export($contents, true).';');
        }
    }

    protected function tearDownWorkspace(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);
    }

    /**
     * @return array<string, array<string, string>>
     */
    abstract protected function langFiles(): array;

    protected function generated(string $relativePath): string
    {
        return (new Filesystem)->get($this->output.'/'.$relativePath);
    }
}
