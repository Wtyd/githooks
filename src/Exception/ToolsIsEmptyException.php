<?php

namespace GitHooks\Exception;

class ToolsIsEmptyException extends \RuntimeException implements GitHooksExceptionInterface
{
    /**
     * @var string Fichero de configuración.
     */
    protected $filePath;

    public static function forFile(string $file): ToolsIsEmptyException
    {
        $exception = new self(
            "El tag'Tools' del fichero '$file' no contiene elementos."
        );

        $exception->filePath = $file;

        return $exception;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
