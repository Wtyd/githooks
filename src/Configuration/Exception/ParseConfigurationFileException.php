<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration\Exception;

use Wtyd\GitHooks\Exception\GitHooksExceptionInterface;

/**
 * The configuration file was found but could not be read as configuration.
 * Implements the GitHooks interface so the runners report it in one line and
 * exit 1 instead of letting it escape with a stack trace.
 */
class ParseConfigurationFileException extends \RuntimeException implements GitHooksExceptionInterface
{
    public static function forMessage(string $message): ParseConfigurationFileException
    {
        $exception = new self(
            $message
        );

        return $exception;
    }
}
