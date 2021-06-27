<?php

namespace Wtyd\GitHooks\LoadTools;

interface ExecutionMode
{
    public const ROOT_PATH = './';

    public const FULL_EXECUTION = 'full';

    public const SMART_EXECUTION = 'smart';

    public const FAST_EXECUTION = 'fast';

    public const EXECUTION_KEY = [self::FULL_EXECUTION, self::SMART_EXECUTION, self::FAST_EXECUTION];

    public function getTools(): array;
}
