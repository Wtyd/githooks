<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

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
        $this->executable = self::PARALLEL_LINT;

        $this->setArguments($toolConfiguration->getToolConfiguration());
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
        return $this->executablePath . $arguments;
    }


    public function setArguments(array $configurationFile): void
    {
        $this->executablePath = $this->routeCorrector($configurationFile[self::EXECUTABLE_PATH_OPTION] ?? self::PARALLEL_LINT);
        if (!empty($configurationFile[self::EXCLUDE])) {
            $this->args[self::EXCLUDE] = $this->multipleRoutesCorrector($configurationFile[self::EXCLUDE]);
        }
        if (!empty($configurationFile[self::PATHS])) {
            $this->args[self::PATHS] = $this->multipleRoutesCorrector($configurationFile[self::PATHS]);
        }
    }
}
