<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\ConfigurationFile\Printer;

use Wtyd\GitHooks\ConfigurationFile\ConfigurationFile;

class ToolsTable extends TableAbstract
{
    public function __construct(array $tools)
    {
        $this->headers = [ConfigurationFile::TOOLS, 'Commands'];

        foreach ($tools as $key => $tool) {
            $this->rows[] = [$key, $tool->prepareCommand()];
        }
    }
}