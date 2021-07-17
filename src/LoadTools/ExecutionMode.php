<?php

namespace Wtyd\GitHooks\LoadTools;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

interface ExecutionMode
{
    public const ROOT_PATH = './';

    public const FULL_EXECUTION = 'full';

    public const FAST_EXECUTION = 'fast';

    public const EXECUTION_KEY = [self::FULL_EXECUTION, self::FAST_EXECUTION];

    public function getTools(ConfigurationFile $configurationFile): array;
}
