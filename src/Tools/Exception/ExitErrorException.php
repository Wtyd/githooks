<?php
namespace GitHooks\Tools\Exception;

class ExitErrorException extends \RuntimeException implements ToolsExceptionInterface
{
    private $exit;

    public static function forExit(array $exit)
    {
        $exception = new self(sprintf(
            'La herramienta ha detectado errores'
        ));

        $exception->exit = $exit;

        return $exception;
    }

    public function getExit() : array
    {
        return $this->exit;
    }
}
