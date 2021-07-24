<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ToolsTagIsNotFoundException extends \RuntimeException implements ConfigurationFileInterface
{
    /**
     * @var string Fichero de configuración.
     */
    protected $filePath;

    public static function forFile(string $file): ToolsTagIsNotFoundException
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
