<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Output;

use Wtyd\Alfil\Execution\FlowResult;

interface ResultFormatter
{
    public function format(FlowResult $result): string;
}
