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
        $origin = 'vendor/wtyd/githooks/qa/githooks.dist.php';
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
            dd($th->getMessage());
            $this->printer->error("Failed to copy $origin to $destiny");
            return 1;
        }
    }
}
