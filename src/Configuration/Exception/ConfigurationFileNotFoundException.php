<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Configuration\Exception;

use Wtyd\Alfil\Exception\AlfilExceptionInterface;

class ConfigurationFileNotFoundException extends \RuntimeException implements AlfilExceptionInterface
{
    public function __construct(string $message = '')
    {
        $this->message = !empty($message)
            ? $message
            : 'Configuration file not found. Expected alfil.php in the project root or in qa/ '
                . '(or pass --config=PATH).';
    }
}
