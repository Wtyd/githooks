<?php

namespace GitHooks;

class Constants
{
    public const CONFIGURATION_FILE = 'configurationFile';

    public const CONFIGURATION_FILE_PATH = 'qa/githooks.yml';

    public const TOOLS = 'Tools';

    public const OPTIONS = 'Options';

    public const EXECUTION = 'execution';

    public const FULL_EXECUTION = 'full';

    public const SMART_EXECUTION = 'smart';

    public const FAST_EXECUTION = 'fast';

    public const EXECUTION_KEY = [self::FULL_EXECUTION, self::SMART_EXECUTION, self::FAST_EXECUTION];

    public const OPTIONS_KEY = [self::EXECUTION];

    public const CODE_SNIFFER = 'phpcs';

    public const CHECK_SECURITY = 'check-security';

    public const PARALLEL_LINT = 'parallel-lint';

    public const MESS_DETECTOR = 'phpmd';

    public const COPYPASTE_DETECTOR = 'phpcpd';

    public const PHPSTAN = 'phpstan';

    public const TOOL_LIST = [
        self::CODE_SNIFFER => Tools\CodeSniffer::class,
        self::CHECK_SECURITY => Tools\CheckSecurity::class,
        self::PARALLEL_LINT => Tools\ParallelLint::class,
        self::MESS_DETECTOR => Tools\MessDetector::class,
        self::COPYPASTE_DETECTOR => Tools\CopyPasteDetector::class,
        self::PHPSTAN => Tools\Stan::class,
    ];

    public const EXCLUDE_ARGUMENT = [
        self::CODE_SNIFFER => Tools\CodeSniffer::IGNORE,
        self::PARALLEL_LINT => Tools\ParallelLint::EXCLUDE,
        self::MESS_DETECTOR => Tools\MessDetector::EXCLUDE,
        self::COPYPASTE_DETECTOR => Tools\CopyPasteDetector::EXCLUDE,
        self::PHPSTAN => '',
    ];

    public const ERRORS = 'errors';

    public const WARNINGS = 'warnings';
}
