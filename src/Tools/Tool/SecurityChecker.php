<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library fabpot/local-php-security-checker
 */
class SecurityChecker extends ToolAbstract
{
    public const NAME = 'local-php-security-checker';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
    ];

    /**
     * @param ToolConfiguration $toolConfiguration
     */
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'local-php-security-checker';
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
