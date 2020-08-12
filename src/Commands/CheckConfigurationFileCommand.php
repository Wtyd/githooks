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
    protected $description = 'Verifica que existe el fichero de configuración githooks.yml en la carpeta ./qa y que tiene el formato adecuado.';

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
        $this->printer->line('Checking the configuration file ' . Constants::CONFIGURATION_FILE_PATH . ':');
        $root = getcwd();
        try {
            $configurationFile = $this->configuration->readFile($root . '/' . Constants::CONFIGURATION_FILE_PATH);

            $errors = $this->configurationFileValidator->__invoke($configurationFile);

            if (!$errors->hasErrors()) {
                $message = 'The file ' . Constants::CONFIGURATION_FILE_PATH . ' has the correct format.';
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
