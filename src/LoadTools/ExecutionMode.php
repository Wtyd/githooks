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
     * Bla bla
     *
     * @param ConfigurationFile $configurationFile
     * @return array<\Wtyd\GitHooks\Tools\Tool\ToolAbstract> Returns the Tools
     */
    public function getTools(ConfigurationFile $configurationFile): array;
}
