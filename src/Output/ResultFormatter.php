<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

use Wtyd\GitHooks\Execution\FlowResult;

interface ResultFormatter
{
    public function format(FlowResult $result): string;
}
