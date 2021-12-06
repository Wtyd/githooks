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

    public const OPTIONS = [
        self::EXECUTABLE_PATH_OPTION,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,
        self::PATHS
    ];

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
        $command = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(',', $this->args[$option]);
                    break;
                case self::EXCLUDE:
                    $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //parallel-lint ./ --exclude qa --exclude tests --exclude vendor
        return $command;
    }


    public function setArguments(array $configurationFile): void
    {
        foreach ($configurationFile as $key => $value) {
            if (!empty($value)) {
                // $this->args[$key] = $this->multipleRoutesCorrector($value);
                $this->args[$key] = $value;
            }
        }
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::PARALLEL_LINT;
        }
    }
}
