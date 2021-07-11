<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

/**
 * Envuelve una Symfony\Component\Yaml\Exception\ParseException
 */
class ParseConfigurationFileException extends \RuntimeException implements ConfigurationFileInterface
{
    public static function forMessage(string $message): ParseConfigurationFileException
    {
        $exception = new self(
            $message
        );

        return $exception;
    }
}
