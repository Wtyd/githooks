<?php

namespace Wtyd\GitHooks\Exception;

class ToolsNotFoundException extends \RuntimeException implements GitHooksExceptionInterface
{
    /**
     * @var string Fichero de configuración.
     */
    protected $filePath;

    public static function forFile(string $file): ToolsNotFoundException
    {
        $exception = new self(
            "There is no 'Tools' key in the '$file' file."
        );

        $exception->filePath = $file;

        return $exception;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
