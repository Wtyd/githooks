<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output;

/**
 * Pre-resolved inputs for {@see ConditionsHeaderEmitter::emit()}. Built by the
 * Command (or Runner) from the parsed CLI flags. Public properties not
 * readonly by PHP 7.4 compatibility — treat as immutable at the boundary.
 */
class HeaderOptions
{
    public string $format;

    public bool $showProgress;

    public function __construct(string $format, bool $showProgress)
    {
        $this->format = $format;
        $this->showProgress = $showProgress;
    }
}
