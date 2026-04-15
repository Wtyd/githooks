<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\ToolOutputParser;

use Wtyd\GitHooks\Output\CodeIssue;

interface ToolOutputParserInterface
{
    /** @return CodeIssue[] */
    public function parse(string $stdout, string $toolName): array;
}
