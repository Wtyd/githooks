<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Output\ToolOutputParser;

use Wtyd\Alfil\Output\CodeIssue;

interface ToolOutputParserInterface
{
    /** @return CodeIssue[] */
    public function parse(string $stdout, string $toolName): array;
}
