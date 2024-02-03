<?php

namespace Wtyd\GitHooks\App\Commands;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use PharData;
use Wtyd\GitHooks\Build\Build;
use Wtyd\GitHooks\Build\ManageDependencies;
use Wtyd\GitHooks\Utils\Printer;

class ExtractBuildCommand extends Command
{


    protected $signature = 'app:extract-build';
    protected $description = 'Extracts the build from the tar file after the build process.';

    /**  @var \Wtyd\GitHooks\Build\Build */
    private $build;

    private $composer = 'tools/composer';

    public function __construct(Build $build)
    {
        $this->build = $build;
        parent::__construct();
    }
    public function handle()
    {
        $this->title('Extract build');

        $this->task(
            '   <fg=yellow>1. Deleting old build</>',
            $this->deletingOldBuild()
        );

        $this->task(
            '   <fg=yellow>2. Extracting build</>',
            $this->extractBuild()
        );

        $this->task(
            '   <fg=yellow>3. Check build</>',
            $this->checkBuild()
        );
    }

    private function deletingOldBuild(): void
    {
        $oldBuildOfActualPhpVersion = $this->build->getBuildPath() . $this->getBinary();
        if (! Storage::exists($oldBuildOfActualPhpVersion)) {
            throw new \Exception("The build $oldBuildOfActualPhpVersion does not exist.");
        }
        if (! Storage::delete($oldBuildOfActualPhpVersion)) {
            throw new \Exception("The build $oldBuildOfActualPhpVersion could not be deleted.");
        }
    }

    private function extractBuild(): void
    {
        $newBuildOfActualPhpVersion = $this->build->getBuildPath() . $this->getBinary();
        $tarFile = File::name($this->build->getTarName()) . DIRECTORY_SEPARATOR . $this->build->getTarName();
        $phar = new PharData($tarFile);
        $phar->extractTo($newBuildOfActualPhpVersion);
    }

    private function checkBuild(): void
    {
        $newBuildOfActualPhpVersion = $this->build->getBuildPath() . $this->getBinary();
        $exit = shell_exec("$newBuildOfActualPhpVersion --version");
        $this->info($exit);
    }

    /**
     * Returns the artisan binary. Copied from BuildCommand
     */
    private function getBinary(): string
    {
        return str_replace(["'", '"'], '', Artisan::artisanBinary());
    }
}
