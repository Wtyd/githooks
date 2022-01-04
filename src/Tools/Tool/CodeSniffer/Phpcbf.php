<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library squizlabs/php_codesniffer
 */
class Phpcbf extends CodeSniffer
{
    public const NAME = self::PHPCBF;

    public const USE_PHPCS_CONFIGURATION = 'usePhpcsConfiguration';

    public const OPTIONS = [
        self::PATHS,
        self::STANDARD,
        self::IGNORE,
        self::ERROR_SEVERITY,
        self::WARNING_SEVERITY,
        self::OTHER_ARGS_OPTION,
        self::USE_PHPCS_CONFIGURATION,
    ];

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'phpcbf';

        $this->setArguments($toolConfiguration->getToolConfiguration());
        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
        $this->args[self::EXECUTABLE_PATH_OPTION] = str_replace(self::PHPCS, self::PHPCBF, $this->args[self::EXECUTABLE_PATH_OPTION]);
    }

    public static function usePhpcsConfiguration(array $phpcbfConfiguration): bool
    {
        return isset($phpcbfConfiguration[self::USE_PHPCS_CONFIGURATION]) && $phpcbfConfiguration[self::USE_PHPCS_CONFIGURATION];
    }
}
