<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

use Wtyd\GitHooks\ConfigurationFile\OptionsConfiguration;
use Wtyd\GitHooks\LoadTools\ExecutionMode;

class WrongOptionsValueException extends \DomainException implements ConfigurationFileInterface
{
    /**
     * @param mixed $executionValue
     * @return WrongOptionsValueException
     */
    public static function forExecution($executionValue): WrongOptionsValueException
    {
        $exception = new self(
            self::getExceptionMessage($executionValue)
        );

        return $exception;
    }

    /**
     * @param mixed $processesValue
     * @return WrongOptionsValueException
     */
    public static function forProcesses($processesValue): WrongOptionsValueException
    {
        $exception = new self(
            self::getExceptionMessageForProcesses($processesValue)
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

    /**
     * @param mixed $processesValue
     * @return string
     */
    public static function getExceptionMessageForProcesses($processesValue): string
    {
        $processesValue = $processesValue === null ? 'null' : $processesValue;
        $processesValue = is_bool($processesValue) ? $processesValue = self::castBooleanToString($processesValue) : $processesValue;

        return "The value '$processesValue' is not allowed for the tag '" . OptionsConfiguration::PROCESSES_TAG . "'. Accepts numbers greater than 0";
    }
}
