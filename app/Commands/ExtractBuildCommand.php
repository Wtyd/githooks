<?php

namespace Wtyd\GitHooks\App\Commands;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use PharData;
use Wtyd\GitHooks\Build\Build;

class ExtractBuildCommand extends Command
{
    protected $signature = 'app:extract-build {--all}';
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

        $allBuilds = boolval($this->option('all'));

        if ($allBuilds) {
            $this->task(
                '   <fg=yellow>1. Extracting all builds</>',
                function () {
                    $builds = Build::ALL_BUILDS;
                    foreach ($builds as $tarFile) {
                        $this->extractBuild($tarFile);
                    }
                }
            );
        } else {
            $this->task(
                '   <fg=yellow>1. Extracting build</>',
                $this->extractBuild()
            );

            $this->task(
                '   <fg=yellow>2. Check build</>',
                $this->checkBuild()
            );
        }
    }

    /**
     * Files are in directory with him name. For example ./githooks-7.1/githooks-7.1.tar
     *
     * @param string $tarName For example: 'githooks-7.1.tar'
     */
    private function extractBuild($tarName = null): void
    {
        $tarName = $tarName ?? $this->build->getTarName();
        $zipFile = File::name($tarName) . DIRECTORY_SEPARATOR . $tarName;
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
