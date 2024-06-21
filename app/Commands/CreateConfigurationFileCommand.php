<?php

namespace Wtyd\GitHooks\App\Commands;

// use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\Utils\Printer;
use Wtyd\GitHooks\Utils\Storage;

class CreateConfigurationFileCommand extends Command
{
    protected $signature = 'conf:init';
    protected $description = 'Creates the configuration file githooks.yml in the project path';

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Printer $printer)
    {
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $origin = "vendor/wtyd/githooks/qa/githooks.dist.yml";
        $destiny = "githooks.yml";

        if ($this->checkIfConfigurationFileExists()) {
            return $this->copyFile($origin, $destiny);
        } else {
            return 1;
        }
    }

    protected function checkIfConfigurationFileExists(): bool
    {
        if (Storage::exists('githooks.yml') || Storage::exists('qa/githooks.yml')) {
            $this->printer->error('Configuration file githooks.yml already exists in root path');
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
