<?php

namespace Wtyd\GitHooks\Exception;

/**
 * Envuelve una Symfony\Component\Yaml\Exception\ParseException
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
