<?php

namespace GitHooks\Commands\Exception;

class InvalidArgumentValueException extends \InvalidArgumentException implements CommandsExceptionInterface
{

    /**
     * Raise an InvalidArgumentValueException
     *
     * @param string $argument Name of the argument
     * @param mixed $expectedValues The invalid value for the argument
     * @param array $values All posible values
     *
     * @return InvalidArgumentValueException
     */
    public static function forArgument(string $argument, $value, array $expectedValues): InvalidArgumentValueException
    {
        $value = strval($value);
        $expectedValues = implode(', ', $expectedValues);
        $exception = new self(sprintf(
            "The argument '$argument' with value '$value' only can have the following values: $expectedValues"
        ));

        return $exception;
    }
}
