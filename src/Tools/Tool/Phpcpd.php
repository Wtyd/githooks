<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library sebastian/phpcpd
 */
class Phpcpd extends ToolAbstract
{
    public const NAME = 'phpcpd';

    public const SUPPORTS_FAST = false;

    public const EXCLUDE = 'exclude';

    public const PATHS = 'paths';

    public const MIN_LINES = 'min-lines';

    public const MIN_TOKENS = 'min-tokens';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::EXCLUDE,
        self::MIN_LINES,
        self::MIN_TOKENS,
        self::OTHER_ARGS_OPTION,
        self::PATHS,
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
                    $command .= ' ' . implode(' ', $this->args[self::PATHS]);
                    break;
                case self::EXCLUDE:
                    $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE], '--exclude ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                case self::MIN_LINES:
                case self::MIN_TOKENS:
                    $command .= ' --' . $option . '=' . $this->args[$option];
                    break;
                case self::IGNORE_ERRORS_ON_EXIT:
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        // tools/php71/phpcpd --exclude vendor --exclude tests ./
        return $command;
    }
}
