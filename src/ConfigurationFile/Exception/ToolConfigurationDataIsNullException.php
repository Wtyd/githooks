<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Exception;

class ToolConfigurationDataIsNullException extends \InvalidArgumentException implements ConfigurationFileInterface
{
    /**
     * Data for the tool
     *
     * @var array|null
     */
    protected $data;

    public static function forData(string $tool, ?array $data): ToolConfigurationDataIsNullException
    {
        $exception = new self("Invalid data to create $tool ToolConfiguration");
        $exception->data = $data;

        return $exception;
    }

    public function getData(): ?array
    {
        return $this->data;
    }
}
