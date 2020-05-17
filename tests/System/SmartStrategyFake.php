<?php

namespace Tests\System;

use GitHooks\Constants;
use GitHooks\LoadTools\SmartStrategy;

class SmartStrategyFake extends SmartStrategy
{
    protected $toolsShouldSkip = [];

    protected function toolShouldSkip(string $tool): bool
    {
        $toolShouldSkip = false;
        if (Constants::CHECK_SECURITY !== $tool) {
            $toolShouldSkip = in_array($tool, $this->toolsShouldSkip);
        }
        return $toolShouldSkip;
    }

    public function setToolsShouldSkip(array $tools): void
    {
        $this->toolsShouldSkip = $tools;
    }
}
