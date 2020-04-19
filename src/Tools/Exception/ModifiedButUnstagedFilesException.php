<?php

namespace GitHooks\Tools\Exception;

class ModifiedButUnstagedFilesException extends \RuntimeException implements ToolsExceptionInterface
{
    /**
     * @var array
     */
    private $exit;

    public static function forExit(array $exit): ModifiedButUnstagedFilesException
    {
        $exception = new self(sprintf(
            'Se han modificado algunos ficheros pero no se ha commiteado'
        ));

        $exception->exit = $exit;

        return $exception;
    }

    public function getExit(): array
    {
        return $this->exit;
    }
}
