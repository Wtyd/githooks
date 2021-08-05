<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Utils\Printer;

class CheckConfigurationFileCommand extends Command
{
    protected $signature = 'conf:check';
    protected $description = 'Check that the githooks.yml configuration file exists and that it is in the proper format.';

    public function __construct(FileReader $fileReader, Printer $printer)
    {
        $this->fileReader = $fileReader;
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $errors = new Errors();
        try {
            $file = $this->fileReader->readfile();

            $configurationFile = new ConfigurationFile($file, ConfigurationFile::ALL_TOOLS);

            $this->info('The file githooks.yml has the correct format.');
        } catch (ConfigurationFileInterface $exception) {
            $this->error($exception->getMessage());
            $errors->setError('set error', 'to return 1');

            // var_dump($exception->getMessage());
            foreach ($exception->getConfigurationFile()->getErrors() as $error) {
                // dd($error);
                $this->printer->resultError($error);
            }
            $this->printWarnings($exception->getConfigurationFile()->getWarnings());
        }

        $exitCode = 0;
        if ($errors->isEmpty()) {
            $this->printWarnings($configurationFile->getWarnings());
        } else {
            $exitCode = 1;
        }



        return $exitCode;
    }

    protected function printWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->printer->resultWarning($warning);
        }
    }
}
