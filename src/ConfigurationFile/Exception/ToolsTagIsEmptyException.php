<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ToolsTagIsEmptyException extends \RuntimeException implements ConfigurationFileInterface
{
    /**
     * @var string Fichero de configuración.
     */
    protected $filePath;

    public static function forFile(string $file): ToolsTagIsEmptyException
    {
        $exception = new self(
            "The 'Tools' key from '$file' file has no items."
        );

        $exception->filePath = $file;

        return $exception;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
