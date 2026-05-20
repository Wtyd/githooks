<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

/**
 * Result of {@see BranchResolver::resolve()}: the current branch name and the
 * source it was read from (cli, env, ci:<platform>, git).
 *
 * Immutable value object.
 */
class BranchResolution
{
    private string $branch;

    private string $source;

    public function __construct(string $branch, string $source)
    {
        $this->branch = $branch;
        $this->source = $source;
    }

    public function getBranch(): string
    {
        return $this->branch;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
