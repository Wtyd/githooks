<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Printer;

abstract class TableAbstract
{
    protected array $headers = [];

    protected array $rows = [];

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}
