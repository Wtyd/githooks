<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library sebastian/phpcpd
 */
class Phpcpd extends ToolAbstract
{
    public const NAME = self::COPYPASTE_DETECTOR;

    public const EXCLUDE = 'exclude';

    public const PATHS = 'paths';

    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::EXCLUDE,
        self::OTHER_ARGS_OPTION,
        self::PATHS,
        self::IGNORE_ERRORS_ON_EXIT,
    ];

    //TODO add --names-exclude option. Is like --exclude but for files. Check 6.* interfaces because bring changes.
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::COPYPASTE_DETECTOR;

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
