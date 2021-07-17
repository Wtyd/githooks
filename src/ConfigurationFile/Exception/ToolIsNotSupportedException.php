<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

use Wtyd\GitHooks\Tools\ToolAbstract;

class ToolIsNotSupportedException extends \UnexpectedValueException implements ConfigurationFileInterface
{
    private $tool;

    public static function forTool(string $tool): ToolIsNotSupportedException
    {
        $supportedTools = implode(', ', array_keys(ToolAbstract::SUPPORTED_TOOLS));

        $exception = new self("The tool $tool is not supported by GiHooks. Tools: $supportedTools");

        $exception->tool = $tool;

        return $exception;
    }

    public function getTool(): string
    {
        return $this->tool;
    }
}
