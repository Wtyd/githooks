<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ToolIsNotSupportedException extends \UnexpectedValueException
{
    private string $tool;

    public static function forTool(string $tool): ToolIsNotSupportedException
    {
        $exception = new self("The tool $tool is not supported by GitHooks.");

        $exception->tool = $tool;

        return $exception;
    }

    public function getTool(): string
    {
        return $this->tool;
    }
}
