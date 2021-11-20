<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library squizlabs/php_codesniffer
 */
class Phpcbf extends CodeSniffer
{
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
    }

    public static function usePhpcsConfiguration(array $phpcbfConfiguration): bool
    {
        return isset($phpcbfConfiguration[self::USE_PHPCS_CONFIGURATION]) && $phpcbfConfiguration[self::USE_PHPCS_CONFIGURATION];
    }

    /**
     * Assures that $executablePath is 'phpcbf'. It could be 'phpcs' if usePhpcsConfiguration is true.
     *
     * @param array $configurationFile
     * @return void
     */
    public function setArguments(array $configurationFile): void
    {
        parent::setArguments($configurationFile);

        $this->args[self::EXECUTABLE_PATH_OPTION] = str_replace(self::CODE_SNIFFER, self::PHPCBF, $this->args[self::EXECUTABLE_PATH_OPTION]);
    }
}
