<?php

namespace Wtyd\GitHooks\Tools\Exception;

class ExecutableNotFoundException extends \RuntimeException implements ToolsExceptionInterface
{
    /**
     * @var string
     */
    private $executable;

    public static function forExec(string $executable): ExecutableNotFoundException
    {
        $exception = new self(sprintf(
            'Command %s not found.',
            $executable
        ));

        $exception->executable = $executable;

        return $exception;
    }

    public function getExecutable(): string
    {
        return $this->executable;
    }
}
