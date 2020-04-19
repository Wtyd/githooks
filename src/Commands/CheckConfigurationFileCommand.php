<?php

namespace GitHooks\Commands;

use GitHooks\Configuration;
use GitHooks\Constants;
use GitHooks\Exception\ParseConfigurationFileException;
use GitHooks\Utils\ColoredMessagesTrait;
use Illuminate\Console\Command;

class CheckConfigurationFileCommand extends Command
{
    use ColoredMessagesTrait;

    protected $signature = 'conf:check';
    protected $description = 'Verifica que existe el fichero de configuración githooks.yml en la carpeta ./qa y que tiene el formato adecuado.';

    /**
     * @var Configuration
     */
    protected $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
        parent::__construct();
    }

    public function handle()
    {
        $root = getcwd();
        $this->line('Verificando el formato de ' . Constants::CONFIGURATION_FILE_PATH . ':');
        try {
            $configurationFile = $this->configuration->readFile($root . '/' . Constants::CONFIGURATION_FILE_PATH);

            $errors = $this->configuration->check($configurationFile);

            if (empty($errors->getErrors())) {
                $this->messageSuccess('El fichero ' . Constants::CONFIGURATION_FILE_PATH . ' tiene el formato correcto.');
            } else {
                $this->messageFailure('El fichero contiene los siguientes errores:');
                echo "\n";
                foreach ($errors->getErrors() as $error) {
                    $this->line("    - $error");
                }
            }

            if (! empty($errors->getWarnings())) {
                foreach ($errors->getWarnings() as $warning) {
                    $this->messageWarning($warning);
                }
            }
        } catch (ParseConfigurationFileException $ex) {
            $this->messageFailure($ex->getMessage());
        }
    }
}
