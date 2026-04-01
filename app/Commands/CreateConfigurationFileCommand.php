<?php

namespace Wtyd\GitHooks\App\Commands;

// use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init {--legacy : Generate legacy (v2) format instead of v3 hooks/flows/jobs}';
    protected $description = 'Creates the configuration file githooks.php in the project path';

    protected Printer $printer;

    protected FileReader $fileReader;

    public function __construct(Printer $printer, FileReader $fileReader)
    {
        $this->printer = $printer;
        $this->fileReader = $fileReader;
        parent::__construct();
    }

    public function handle()
    {
        $distFile = $this->option('legacy')
            ? 'githooks.dist.yml'
            : 'githooks.dist.php';
        $destiny = 'githooks.php';

        try {
            $this->fileReader->findConfigurationFile();
        } catch (ConfigurationFileNotFoundException $ex) {
            // Try as installed dependency first, then local development path
            $origin = $this->resolveDistFile($distFile);

            if ($origin === null) {
                $this->printer->error("Distribution file '$distFile' not found.");
                return 1;
            }

            return $this->copyFile($origin, $destiny);
        }

        $this->printer->error('githooks configuration file already exists');
        return 1;
    }

    protected function resolveDistFile(string $distFile): ?string
    {
        $candidates = [
            "vendor/wtyd/githooks/qa/$distFile",
            "qa/$distFile",
        ];

        foreach ($candidates as $path) {
            if (Storage::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function copyFile(string $origin, string $destiny): int
    {
        try {
            // For php <8.0 when copy fails raise FileNotFoundException. False when >=8.0
            if (Storage::copy($origin, $destiny)) {
                $this->printer->success('Configuration file githooks.php has been created in root path');
                return 0;
            }
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        } catch (\Throwable $th) {
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        }
    }
}
