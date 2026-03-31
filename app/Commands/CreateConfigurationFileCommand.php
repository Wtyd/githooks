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
        $origin = $this->option('legacy')
            ? 'vendor/wtyd/githooks/qa/githooks.dist.php'
            : 'vendor/wtyd/githooks/qa/githooks.v3.dist.php';
        $destiny = 'githooks.php';

        try {
            $this->fileReader->findConfigurationFile();
        } catch (ConfigurationFileNotFoundException $ex) {
            return $this->copyFile($origin, $destiny);
        }

        $this->printer->error('githooks configuration file already exists');
        return 1;
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
