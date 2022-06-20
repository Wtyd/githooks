<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\Tools\Tool\ToolAbstract;

/**
 * Library squizlabs/php_codesniffer
 */
abstract class CodeSniffer extends ToolAbstract
{
    public const PATHS = 'paths';

    public const STANDARD = 'standard';

    public const IGNORE = 'ignore';

    public const ERROR_SEVERITY = 'error-severity';

    public const WARNING_SEVERITY = 'warning-severity';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PATHS,
        self::STANDARD,
        self::IGNORE,
        self::ERROR_SEVERITY,
        self::WARNING_SEVERITY,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
    ];

    // TODO Fix Cyc. Complexity
    public function prepareCommand(): string
    {
        $command = $this->args[self::EXECUTABLE_PATH_OPTION];
        $arguments = array_diff(self::ARGUMENTS, [self::EXECUTABLE_PATH_OPTION]);

        foreach ($arguments as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::PATHS:
                    $command .= ' ' . implode(' ', $this->args[$option]);
                    break;
                case self::STANDARD:
                case self::ERROR_SEVERITY:
                case self::WARNING_SEVERITY:
                    $command .= " --$option=" . $this->args[$option];
                    break;
                case self::IGNORE:
                    $command .= " --$option=" . implode(',', $this->args[$option]);
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //args = '--report=full'
        //phpcs src --standard=./qa/psr12-ruleset.xml --ignore=vendor,otrodir --error-severity=1 --warning-severity=6 --report=full
        return $command;
    }
}
