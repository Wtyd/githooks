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
    protected $signature = 'conf:init';
    protected $description = 'Creates the configuration file githooks.yml in the project path';

    /** @var \Wtyd\GitHooks\Utils\Printer */
    protected $printer;

    /** @var \Wtyd\GitHooks\ConfigurationFile\FileReader */
    protected $fileReader;

    public function __construct(Printer $printer, FileReader $fileReader)
    {
        $this->printer = $printer;
        $this->fileReader = $fileReader;
        parent::__construct();
    }

    public function handle()
    {
        $origin = "vendor/wtyd/githooks/qa/githooks.dist.yml";
        $destiny = "githooks.yml";

        try {
            $this->fileReader->findConfigurationFile();
        } catch (ConfigurationFileNotFoundException $ex) {
            $this->copyFile($origin, $destiny);
            return 0;
        }

        $this->printer->error('githooks.yml configuration file already exists');
        return 1;
    }

    protected function checkIfConfigurationFileExists(): bool
    {
        if (Storage::exists('githooks.yml') || Storage::exists('qa/githooks.yml')) {
            $this->printer->error('githooks.yml configuration file already exists');
            return false;
        }
        return true;
    }

    protected function copyFile(string $origin, string $destiny): int
    {
        try {
            if (!Storage::copy($origin, $destiny)) {
                $this->printer->error("Failed to copy $origin to $destiny");
                return 1;
            } else {
                $this->printer->success('Configuration file githooks.yml has been created in root path');
                return 0;
            }
        } catch (\Throwable $th) {
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        }
    }
}
