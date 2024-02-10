<?php

namespace Wtyd\GitHooks\App\Commands\Exception;

class InvalidArgumentValueException extends \InvalidArgumentException implements CommandsExceptionInterface
{
    /**
     * Raise an InvalidArgumentValueException
     *
     * @param string $argument Name of the argument
     * @param mixed $value All posible values
     * @param array $expectedValues The invalid value for the argument
     */
    public static function forArgument(string $argument, mixed $value, array $expectedValues): self
    {
        $value = strval($value);
        $expectedValues = implode(', ', $expectedValues);
        $exception = new self(sprintf(
            "The argument '$argument' with value '$value' only can have the following values: $expectedValues"
        ));

        return $exception;
    }
}
