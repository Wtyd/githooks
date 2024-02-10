<?php

namespace Wtyd\GitHooks\App\Commands;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use PharData;
use Wtyd\GitHooks\Build\Build;

class ExtractBuildCommand extends Command
{
    protected $signature = 'app:extract-build';
    protected $description = 'Extracts the build from the tar file after the build process.';

    /**  @var \Wtyd\GitHooks\Build\Build */
    private $build;

    public function __construct(Build $build)
    {
        $this->build = $build;
        parent::__construct();
    }
    public function handle()
    {
        $this->title('Extract build');

        $this->task(
            '   <fg=yellow>1. Extracting build</>',
            $this->extractBuild()
        );

        $this->task(
            '   <fg=yellow>2. Check build</>',
            $this->checkBuild()
        );
    }

    private function extractBuild(): void
    {
        $zipFile = File::name($this->build->getTarName()) . DIRECTORY_SEPARATOR . $this->build->getTarName();
        $zip = new PharData($zipFile);
        $resultado = $zip->extractTo('./', null, true); // extract to $this->build->getBuildPath();
        if (true === $resultado) {
            $this->info("File extracted successfully");
        } else {
            $this->warn("File not extracted successfully");
        }
    }

    private function checkBuild(): void
    {
        $newBuildOfActualPhpVersion = $this->build->getBuildPath() . $this->getBinary();
        exec("$newBuildOfActualPhpVersion --version", $output, $exitCode);
        $this->info(implode("\n", $output));
        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }

    /**
     * Returns the artisan binary. Copied from BuildCommand
     */
    private function getBinary(): string
    {
        return str_replace(["'", '"'], '', Artisan::artisanBinary());
    }
}
