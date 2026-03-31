<?php

namespace Wtyd\GitHooks\LoadTools\Exception;

class ToolDoesNotExistException extends \DomainException implements LoadToolsExceptionInterface
{
    private string $tool;

    public static function forTool(string $tool): ToolDoesNotExistException
    {
        $exception = new self(sprintf(
            'The %s tool is not supported by GiHooks.',
            $tool
        ));

        $exception->tool = $tool;

        return $exception;
    }

    public function getTool(): string
    {
        return $this->tool;
    }
}
