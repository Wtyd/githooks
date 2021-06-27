<?php

namespace Wtyd\GitHooks;

class Constants
{
    public const CONFIGURATION_FILE = 'configurationFile';

    public const TOOLS = 'Tools';

    public const OPTIONS = 'Options';

    public const EXECUTION = 'execution';

    public const FULL_EXECUTION = 'full';

    public const SMART_EXECUTION = 'smart';

    public const FAST_EXECUTION = 'fast';

    public const EXECUTION_KEY = [self::FULL_EXECUTION, self::SMART_EXECUTION, self::FAST_EXECUTION];

    public const OPTIONS_KEY = [self::EXECUTION];

    public const ERRORS = 'errors';

    public const WARNINGS = 'warnings';
}
