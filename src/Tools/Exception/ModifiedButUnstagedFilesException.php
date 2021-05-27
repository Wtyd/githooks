<?php

namespace Wtyd\GitHooks\Tools\Exception;

class ModifiedButUnstagedFilesException extends \RuntimeException implements ToolsExceptionInterface
{
    /**
     * @var array
     */
    private $exit;

    public static function forExit(array $exit): ModifiedButUnstagedFilesException
    {
        $exception = new self(sprintf(
            'Some files have been modified but it has not been committed'
        ));

        $exception->exit = $exit;

        return $exception;
    }

    public function getExit(): array
    {
        return $this->exit;
    }
}
