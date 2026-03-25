<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

class Psalm extends ToolAbstract
{
    public const NAME = self::PSALM;
    public const PSALM_CONFIGURATION_FILE = 'config';
    public const MEMORY_LIMIT = 'memory-limit';
    public const THREADS = 'threads';
    public const NO_DIFF = 'no-diff';
    public const OUTPUT_FORMAT = 'output-format';
    public const PLUGIN = 'plugin';
    public const USE_BASELINE = 'use-baseline';
    public const PATHS = 'paths';
    public const REPORT = 'report';
    public const ARGUMENTS = [
        self::PATHS,
        self::EXECUTABLE_PATH_OPTION,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
        self::PSALM_CONFIGURATION_FILE,
        self::MEMORY_LIMIT,
        self::THREADS,
        self::NO_DIFF,
        self::OUTPUT_FORMAT,
        self::PLUGIN,
        self::USE_BASELINE,
        self::REPORT,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::PSALM;
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
                    // Nothing to do here, paths are added in the end of the command
                    break;
                case self::PSALM_CONFIGURATION_FILE:
                case self::MEMORY_LIMIT:
                case self::THREADS:
                case self::OUTPUT_FORMAT:
                case self::PLUGIN:
                case self::REPORT:
                case self::USE_BASELINE:
                    $command .= " --$option=" . $this->args[$option];
                    break;
                case self::NO_DIFF:
                    $command .= $this->args[self::NO_DIFF] ? ' --no-diff' : '';
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }
        if (isset($this->args[self::PATHS]) && !empty($this->args[self::PATHS])) {
            $command .= ' ' . implode(' ', $this->args[self::PATHS]);
        }
        return $command;
    }

    public function getArguments(): array
    {
        return $this->args;
    }
}
