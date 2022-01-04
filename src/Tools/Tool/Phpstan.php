<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Ejecuta la libreria phpstan/phpstan
 */
class Phpstan extends ToolAbstract
{
    public const NAME = self::PHPSTAN;

    public const PHPSTAN_CONFIGURATION_FILE = 'config';

    public const LEVEL = 'level';

    public const PATHS = 'paths';

    public const MEMORY_LIMIT = 'memory-limit';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PHPSTAN_CONFIGURATION_FILE,
        self::LEVEL,
        self::MEMORY_LIMIT,
        self::OTHER_ARGS_OPTION,
        self::PATHS,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::PHPSTAN;

        $this->setArguments($toolConfiguration->getToolConfiguration());
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    /**
     * Crea la cadena de argumentos que ejecutará phpstan
     * -c (--configuration): .neon file with extra configuration
     * -l (--level): rule level from 0 (default) to 8
     * --memory-limit: increase memory limit by default of php. Example values: 1G, 1M 1024M
     * paths: directories with code to analyse.
     *
     * @return string Example return: analyse -c=phpstan.neon --no-progress --ansi -l=1 --memory-limit=1G ./src1 ./src2
     */
    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::ARGUMENTS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION] . ' analyse';
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(',', $this->args[$option]);
                    break;
                case self::PHPSTAN_CONFIGURATION_FILE:
                    $command .= ' -c ' . $this->args[self::PHPSTAN_CONFIGURATION_FILE];
                    break;
                case self::LEVEL:
                    $command .= ' -l ' . $this->args[self::LEVEL];
                    break;
                case self::MEMORY_LIMIT:
                    $command .= ' --memory-limit=' . $this->args[self::MEMORY_LIMIT];
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        return $command;
    }
}
