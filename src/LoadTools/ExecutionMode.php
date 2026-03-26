<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

interface ExecutionMode
{
    public const ROOT_PATH = './';

    public const FULL_EXECUTION = 'full';

    public const FAST_EXECUTION = 'fast';

    public const EXECUTION_KEY = [self::FULL_EXECUTION, self::FAST_EXECUTION];

    /**
     * @param ConfigurationFile $configurationFile
     * @return array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract>
     */
    public function getTools(ConfigurationFile $configurationFile): array;

    /**
     * Process a subset of tool configurations.
     *
     * @param array<\Wtyd\GitHooks\ConfigurationFile\ToolConfiguration> $toolConfigurations
     * @param ConfigurationFile $configurationFile Used for adding warnings.
     * @return array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract>
     */
    public function processTools(array $toolConfigurations, ConfigurationFile $configurationFile): array;
}
