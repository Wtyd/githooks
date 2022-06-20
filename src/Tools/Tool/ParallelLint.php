<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library php-parallel-lint/php-parallel-lint
 */
class ParallelLint extends ToolAbstract
{
    public const NAME = self::PARALLEL_LINT;

    public const EXCLUDE = 'exclude';

    public const PATHS = 'paths';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,
        self::PATHS,
        self::IGNORE_ERRORS_ON_EXIT,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::PARALLEL_LINT;

        $this->setArguments($toolConfiguration->getToolConfiguration());

        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    public function prepareCommand(): string
    {
        $command = '';
        foreach (self::ARGUMENTS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(' ', $this->args[$option]);
                    break;
                case self::EXCLUDE:
                    $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //parallel-lint ./ --exclude qa --exclude tests --exclude vendor
        return $command;
    }
}
