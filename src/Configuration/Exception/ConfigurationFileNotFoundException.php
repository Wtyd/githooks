<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration\Exception;

use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

class ConfigurationFileNotFoundException extends \RuntimeException implements GitHooksExceptionInterface
{
    public function __construct(string $message = '')
    {
        $this->message = !empty($message)
            ? $message
            : 'Configuration file not found. Expected githooks.php in the project root or in qa/ '
                . '(or pass --config=PATH).';
    }
}
