<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

class Phpunit extends ToolAbstract
{
    public const NAME = self::PHPUNIT;
    public const GROUP = 'group';
    public const EXCLUDE_GROUP = 'exclude-group';
    public const FILTER = 'filter';
    public const CONFIGURATION = 'configuration';
    public const LOG_JUNIT = 'log-junit';
    public const ARGUMENTS = [
        self::EXECUTABLE_PATH_OPTION,
        self::GROUP,
        self::EXCLUDE_GROUP,
        self::FILTER,
        self::OTHER_ARGS_OPTION,
        self::IGNORE_ERRORS_ON_EXIT,
        self::CONFIGURATION,
        self::LOG_JUNIT,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = self::PHPUNIT;

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
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION] ;
                    break;
                case self::GROUP:
                    $prefix = $this->addPrefixToArray($this->args[self::GROUP], '--group ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                case self::EXCLUDE_GROUP:
                    $prefix = $this->addPrefixToArray($this->args[self::EXCLUDE_GROUP], '--exclude-group ');
                    $command .= ' ' . implode(' ', $prefix);
                    break;
                case self::FILTER:
                    $command .= ' --filter ' . $this->args[self::FILTER];
                    break;
                case self::CONFIGURATION:
                    $command .= ' -c ' . $this->args[self::CONFIGURATION];
                    break;
                case self::LOG_JUNIT:
                    $command .= ' --log-junit ' . $this->args[self::LOG_JUNIT];
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

    public function getArguments(): array
    {
        return $this->args;
    }
}
