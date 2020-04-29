<?php

namespace GitHooks\Commands;

use GitHooks\Configuration;
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
     * @var Printer
     */
    protected $printer;

    public function __construct(Configuration $configuration, Printer $printer)
    {
        $this->configuration = $configuration;
        $this->printer = $printer;
        parent::__construct();
    }

    public function handle()
    {
        $root = getcwd();
        //TODO Pablo: cambiar $this->line por printer
        $this->line('Verificando el formato de ' . Constants::CONFIGURATION_FILE_PATH . ': ' . "\n");
        try {
            $configurationFile = $this->configuration->readFile($root . '/' . Constants::CONFIGURATION_FILE_PATH);

            $errors = $this->configuration->check($configurationFile);

            if (empty($errors->getErrors())) {
                $message = 'El fichero ' . Constants::CONFIGURATION_FILE_PATH . ' tiene el formato correcto.';
                $this->printer->resultSuccess($message);
            } else {
                $message = 'El fichero contiene los siguientes errores:';
                $this->printer->resultError($message);
                foreach ($errors->getErrors() as $error) {
                    //TODO Pablo: cambiar $this->line por printer
                    $this->printer->info("    - $error");
                }
            }

            if (! empty($errors->getWarnings())) {
                foreach ($errors->getWarnings() as $warning) {
                    $this->printer->resultWarning($warning);
                }
            }
        } catch (ParseConfigurationFileException $ex) {
            $this->printer->resultError($ex->getMessage());
        }
    }
}
