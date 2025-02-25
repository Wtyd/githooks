<?php

namespace Wtyd\GitHooks\App\Commands;

use LaravelZero\Framework\Commands\Command;
use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileInterface;
use Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException;
use Wtyd\GitHooks\ConfigurationFile\FileReader;
use Wtyd\GitHooks\ConfigurationFile\Printer\OptionsTable;
use Wtyd\GitHooks\ConfigurationFile\Printer\ToolsTable;
use Wtyd\GitHooks\Tools\Errors;
use Wtyd\GitHooks\Tools\ToolsPreparer;
use Wtyd\GitHooks\Utils\Printer;

class CheckConfigurationFileCommand extends Command
{
    protected $signature = 'conf:check  {-c|--config= : Path to configuration file}';
    protected $description = 'Check that the configuration file exists and that it is in the proper format.';

    /** @var  FileReader */
    protected $fileReader;

    /** @var  Printer */
    protected $printer;

    /** @var  ToolsPreparer */
    protected $toolsPreparer;

    public function __construct(FileReader $fileReader, Printer $printer, ToolsPreparer $toolsPreparer)
    {
        $this->fileReader = $fileReader;
        $this->printer = $printer;
        $this->toolsPreparer = $toolsPreparer;
        parent::__construct();
    }

    public function handle()
    {
        $errors = new Errors();
        try {
            $file = $this->fileReader->readfile(strval($this->option('config')));

            $configurationFile = new ConfigurationFile($file, ConfigurationFile::ALL_TOOLS);

            $optionsTable = new OptionsTable($configurationFile);
            $this->table(
                $optionsTable->getHeaders(),
                $optionsTable->getRows()
            );

            $tools = $this->toolsPreparer->__invoke($configurationFile);

            $toolsTable = new ToolsTable($tools);

            $this->table(
                $toolsTable->getHeaders(),
                $toolsTable->getRows()
            );

            $this->info('The configuration file has the correct format.');
        } catch (ConfigurationFileNotFoundException $exception) {
            $errors->setError('set error', 'to return 1');
            $this->printer->resultError($exception->getMessage());
        } catch (ConfigurationFileInterface $exception) {
            $this->error($exception->getMessage());
            $errors->setError('set error', 'to return 1');

            foreach ($exception->getConfigurationFile()->getErrors() as $error) {
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
