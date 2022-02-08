<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Tools\Tool\CodeSniffer;

use Wtyd\GitHooks\ConfigurationFile\ToolConfiguration;

/**
 * Library squizlabs/php_codesniffer
 */
class Phpcs extends CodeSniffer
{
    public const NAME = self::PHPCS;

    public function __construct(ToolConfiguration $toolConfiguration)
    {
        $this->executable = 'phpcs';

        $this->setArguments($toolConfiguration->getToolConfiguration());

        if (empty($this->args[self::EXECUTABLE_PATH_OPTION])) {
            $this->args[self::EXECUTABLE_PATH_OPTION] = self::NAME;
        }
    }
}
