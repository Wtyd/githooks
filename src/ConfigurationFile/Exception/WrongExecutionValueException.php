<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

use Wtyd\GitHooks\ConfigurationFile\OptionsConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class WrongExecutionValueException extends \DomainException implements ConfigurationFileInterface
{
    /**
     * @param mixed $executionValue
     * @return WrongExecutionValueException
     */
    public static function forExecution($executionValue): WrongExecutionValueException
    {
        $exception = new self(
            self::getExceptionMessage($executionValue)
        );

        return $exception;
    }

    protected static function castBooleanToString(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param mixed $executionValue
     * @return string
     */
    public static function getExceptionMessage($executionValue): string
    {
        $valuesToString = implode(', ', ExecutionMode::EXECUTION_KEY);
        if (is_bool($executionValue)) {
            $executionValue = self::castBooleanToString($executionValue);
        }
        return "The value '$executionValue' is not allowed for the tag '" . OptionsConfiguration::EXECUTION_TAG . "'. Accept: $valuesToString";
    }
}
