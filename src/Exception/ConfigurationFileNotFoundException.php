<?php

namespace Wtyd\GitHooks\Exception;

class ConfigurationFileNotFoundException extends \RuntimeException implements GitHooksExceptionInterface
{
    public function __construct()
    {
        $this->message =  "Configuration file must be 'githooks.yml' in root directory or in qa/ directory";
    }
}
