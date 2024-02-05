<?php

namespace Wtyd\GitHooks\App\Commands;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Build\Build;
use ZipArchive;

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
        $zipFile = File::name($this->build->getTarName());
        // dd($zipFile,$this->build->getTarName());
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \Exception("The build $zipFile could not be extracted.");
        }
        $zip->extractTo($newBuildOfActualPhpVersion);
        $zip->close();
    }

    private function checkBuild(): void
    {
        $newBuildOfActualPhpVersion = $this->build->getBuildPath() . $this->getBinary();
        exec("$newBuildOfActualPhpVersion --version", $output, $exitCode);
        $this->info($output);
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
