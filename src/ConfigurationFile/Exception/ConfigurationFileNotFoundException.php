<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

class ConfigurationFileNotFoundException extends \RuntimeException implements GitHooksExceptionInterface
{
    public function __construct(string $message = '')
    {
        $this->message = !empty($message)
            ? $message
            : "Configuration file must be 'githooks.yml' in root directory or in qa/ directory";
    }
}
