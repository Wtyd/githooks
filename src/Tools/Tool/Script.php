<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use InvalidArgumentException;
use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Generic tool type for running custom QA scripts not natively supported by GitHooks.
 * Only supports the three common attributes: executablePath, otherArguments, ignoreErrorsOnExit.
 * executablePath is mandatory — there is no default fallback.
 */
class Script extends ToolAbstract
{
    public const NAME = 'script';

    public const SUPPORTS_FAST = false;

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
        self::FAIL_FAST,
    ];

    /**
     * @param ToolConfiguration $toolConfiguration
     *
     * @throws \InvalidArgumentException If executablePath is not provided.
     */
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->setArguments($toolConfiguration->getToolConfiguration());

        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            throw new InvalidArgumentException(
                "The 'executablePath' option is required for the 'script' tool. There is no default executable."
            );
        }

        $this->executable = $this->args[self::EXECUTABLE_PATH_OPTION];
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
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        return $command;
    }
}
