<?php

namespace GitHooks\Tools\Exception;

class ExecutableNotFoundException extends \RuntimeException implements ToolsExceptionInterface
{
    private $executable;

    public static function forExec(string $executable)
    {
        $exception = new self(sprintf(
            'No se encuentra el commando %s.',
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
