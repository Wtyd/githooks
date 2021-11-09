<?php

namespace Wtyd\GitHooks\Tools\Tool;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * https://github.com/fabpot/local-php-security-checker
 */
class SecurityChecker extends ToolAbstract
{
    public const OPTIONS = [];
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
        return $this->executablePath;
    }

    public function setArguments(array $configurationFile): void
    {
        $this->executablePath = $this->routeCorrector($configurationFile[self::EXECUTABLE_PATH_OPTION] ?? 'local-php-security-checker');
    }
}
