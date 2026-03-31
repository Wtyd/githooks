<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library phpmd/phpmd
 *
 * Known limitation: phpmd returns exit 0 on syntax errors in source files,
 * which means those files are silently skipped without reporting an error.
 */
class Phpmd extends ToolAbstract
{
    public const NAME = 'phpmd';

    public const SUPPORTS_FAST = true;

    public const RULES = 'rules';

    public const EXCLUDE = 'exclude';

    public const PATHS = 'paths';

    public const CACHE = 'cache';

    public const CACHE_FILE = 'cache-file';

    public const CACHE_STRATEGY = 'cache-strategy';

    public const SUFFIXES = 'suffixes';

    public const BASELINE_FILE = 'baseline-file';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::PATHS,
        self::RULES,
        self::EXCLUDE,
        self::CACHE,
        self::CACHE_FILE,
        self::CACHE_STRATEGY,
        self::SUFFIXES,
        self::BASELINE_FILE,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
        self::FAIL_FAST,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::NAME;

        $this->setArguments($toolConfiguration->getToolConfiguration());
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function prepareCommand(): string
    {
        $command = '';
        foreach (self::ARGUMENTS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command = $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                case self::PATHS:
                    $command .= ' ' . implode(',', $this->args[$option]);
                    break;
                case self::RULES:
                    $command .= ' ansi ' . $this->args[$option];
                    break;
                case self::EXCLUDE:
                    $command .= ' --exclude "' . implode(',', $this->args[$option]) . '"';
                    break;
                case self::CACHE:
                    $command .= ' --cache';
                    break;
                case self::CACHE_FILE:
                case self::CACHE_STRATEGY:
                case self::SUFFIXES:
                case self::BASELINE_FILE:
                    $command .= ' --' . $option . '=' . $this->args[$option];
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        //tools/php71/phpmd src ansi ./qa/phpmd-ruleset.xml --exclude "vendor"
        return $command;
    }
}
