<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * https://github.com/fabpot/local-php-security-checker
 */
class SecurityChecker extends ToolAbstract
{
    public const OPTIONS = [
        self::EXECUTABLE_PATH_OPTION,
        self::OTHER_ARGS_OPTION,
    ];
    /**
     * @param ToolConfiguration $toolConfiguration
     */
    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'local-php-security-checker';
        $this->setArguments($toolConfiguration->getToolConfiguration());
    }

    protected function prepareCommand(): string
    {
        $command = '';
        foreach (self::OPTIONS as $option) {
            if (empty($this->args[$option])) {
                continue;
            }

            switch ($option) {
                case self::EXECUTABLE_PATH_OPTION:
                    $command .= $this->args[self::EXECUTABLE_PATH_OPTION];
                    break;
                default:
                    $command .= ' ' . $this->args[self::OTHER_ARGS_OPTION];
                    break;
            }
        }

        return $command;
    }

    public function setArguments(array $configurationFile): void
    {
        foreach ($configurationFile as $key => $value) {
            if (!empty($value)) {
                $this->args[$key] = $value;
            }
        }
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = 'local-php-security-checker';
        }
    }
}
