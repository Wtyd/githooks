<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

interface GitStagerInterface
{
    /**
     * Re-stages files that are already in the index (cached) to capture modifications
     * made by auto-fixing tools (e.g. phpcbf).
     *
     * @return void
     */
    public function stageTrackedFiles(): void;
}
