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

    /**
     * Arguments that map to --key=value flags.
     */
    private const KEY_VALUE_ARGS = [
        self::PSALM_CONFIGURATION_FILE,
        self::MEMORY_LIMIT,
        self::THREADS,
        self::OUTPUT_FORMAT,
        self::PLUGIN,
        self::REPORT,
        self::USE_BASELINE,
    ];

    /**
     * Arguments that map to boolean flags (--flag when true).
     */
    private const BOOLEAN_ARGS = [
        self::NO_DIFF,
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
        $command = $this->args[self::EXECUTABLE_PATH_OPTION];

        foreach (self::KEY_VALUE_ARGS as $option) {
            if (!empty($this->args[$option])) {
                $command .= " --$option=" . $this->args[$option];
            }
        }

        foreach (self::BOOLEAN_ARGS as $option) {
            if (!empty($this->args[$option])) {
                $command .= " --$option";
            }
        }

        if (!empty($this->args[self::OTHER_ARGS_OPTION])) {
            $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
        }

        if (!empty($this->args[self::PATHS])) {
            $command .= ' ' . implode(' ', $this->args[self::PATHS]);
        }

        return $command;
    }

    public function getArguments(): array
    {
        return $this->args;
    }
}
