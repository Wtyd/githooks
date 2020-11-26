<?php

namespace GitHooks\Commands;

use GitHooks\Configuration;
use GitHooks\ConfigurationFileValidator;
use GitHooks\Constants;
use GitHooks\Exception\ParseConfigurationFileException;
use GitHooks\Utils\Printer;
use Illuminate\Console\Command;

class CheckConfigurationFileCommand extends Command
{
    protected $signature = 'conf:check';
    protected $description = 'Check that the githooks.yml configuration file exists and that it is in the proper format.';

    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var ConfigurationFileValidator
     */
    protected $configurationFileValidator;

    /**
     * @var Printer
     */
    protected $printer;

    public function __construct(Configuration $configuration, ConfigurationFileValidator $configurationFileValidator, Printer $printer)
    {
        $this->configuration = $configuration;
        $this->configurationFileValidator = $configurationFileValidator;
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $this->printer->line('Checking the configuration file:');

        try {
            $configurationFile = $this->configuration->readFile();

            $errors = $this->configurationFileValidator->__invoke($configurationFile);

            if (!$errors->hasErrors()) {
                $message = 'The file githooks.yml has the correct format.';
                $this->printer->resultSuccess($message);
            } else {
                $message = 'The file contains the following errors:';
                $this->printer->resultError($message);
                foreach ($errors->getAllErrors() as $error) {
                    $this->printer->line("    - $error");
                }
            }

            if ($errors->hasWarnings()) {
                foreach ($errors->getAllWarnings() as $warning) {
                    $this->printer->resultWarning($warning);
                }
            }
        } catch (ParseConfigurationFileException $ex) {
            $this->printer->resultError($ex->getMessage());
        }
    }
}
