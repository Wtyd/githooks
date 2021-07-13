<?php

namespace Wtyd\GitHooks\Tools;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;
use Wtyd\GitHooks\Constants;
use Wtyd\GitHooks\Tools\Exception\ExecutableNotFoundException;

/**
 * Ejecuta la libreria jakub-onderka/php-parallel-lint
 */
class ParallelLint extends ToolAbstract
{
    /**
     * @var string EXCLUDES Tag de configuracion de directorios excluidos en el fichero de configuracion .yml. Su valor es un array de strings.
     */
    public const EXCLUDE = 'exclude';

    /**
     * @var string PATHS Tag que indica la ruta sobre la que se ejecutará parallel-lint en el fichero de configuracion .yml
     */
    public const PATHS = 'paths';

    public const OPTIONS = [self::EXCLUDE, self::PATHS];

    /**
     * @var array
     */
    protected $args;

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->installer = 'php-parallel-lint/php-parallel-lint';

        $this->executable = self::PARALLEL_LINT;

        $this->setArguments($toolConfiguration->getToolConfiguration());

        parent::__construct();
    }

    protected function prepareCommand(): string
    {
        $exclude = '';
        if (!empty($this->args[self::EXCLUDE])) {
            $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
            $exclude = implode(' ', $prefix);
        }

        $paths = ''; // If path is empty phpmd will not work
        if (!empty($this->args[self::PATHS])) {
            $paths = implode(' ', $this->args[self::PATHS]);
        }

        $arguments = ' ' . $paths . ' ' . $exclude;

        //parallel-lint ./ --exclude qa --exclude tests --exclude vendor
        return $this->executable . $arguments;
    }

    /**
     * Devuelve la primera versión del ejecutable que encuentra. La prioridad de búsqueda es local > .phar > global . Si no encuentra ninguna versión lanza excepción.
     *
     * @return string Ruta completa al ejecutable. Si no lo encuentra lanza una ExecutableNotFoundException
     */
    protected function executableFinder(): string
    {
        try {
            return parent::executableFinder();
        } catch (\Throwable $th) {
            if ('php-parallel-lint/php-parallel-lint' === $this->installer) {
                $global = 'composer global show jakub-onderka/php-parallel-lint';

                if ($this->libraryCheck($global)) {
                    return $this->executable;
                }
            }
            throw ExecutableNotFoundException::forExec($this->executable);
        }
    }

    /**
     * Lee los argumentos y los setea.
     *
     * @param array $configurationFile
     * @return void
     */
    public function setArguments(array $arguments)
    {
        if (!empty($arguments[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $this->routeCorrector($arguments[self::EXCLUDE]);
        }
        if (!empty($arguments[self::PATHS])) {
            $this->args[self::PATHS] = $this->routeCorrector($arguments[self::PATHS]);
        }
    }
}
