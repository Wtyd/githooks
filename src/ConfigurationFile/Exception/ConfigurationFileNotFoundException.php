<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ConfigurationFileNotFoundException extends \RuntimeException implements ConfigurationFileInterface
{
    public function __construct()
    {
        $this->message =  "Configuration file must be 'githooks.yml' in root directory or in qa/ directory";
    }
}
