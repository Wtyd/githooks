<?php

namespace GitHooks\Tools\Exception;

class ExitErrorException extends \RuntimeException implements ToolsExceptionInterface
{
    /**
     * @var array
     */
    private $exit;

    public static function forExit(array $exit): ExitErrorException
    {
        $exception = new self(sprintf(
            'An error has occurred'
        ));

        $exception->exit = $exit;

        return $exception;
    }

    public function getExit(): array
    {
        return $this->exit;
    }
}
