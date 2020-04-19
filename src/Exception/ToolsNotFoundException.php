<?php

namespace GitHooks\Exception;

class ToolsNotFoundException extends \RuntimeException implements GitHooksExceptionInterface
{
    /**
     * @var string Fichero de configuración.
     */
    protected $filePath;

    public static function forFile(string $file): ToolsNotFoundException
    {
        $exception = new self(
            "No se encuentra el tag 'Tools' en el fichero '$file'."
        );

        $exception->filePath = $file;

        return $exception;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
