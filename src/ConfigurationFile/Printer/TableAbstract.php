<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Printer;

abstract class TableAbstract
{
    /** @var  array */
    protected $headers = [];

    /** @var  array */
    protected $rows = [];

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRows(): array
    {
        return $this->rows;
    }
}
