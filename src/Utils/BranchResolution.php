<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Utils;

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

    /** Read from GITHOOKS_BRANCH, the pre-4.0 name of ALFIL_BRANCH. */
    private bool $legacyEnvVar = false;

    public function __construct(string $branch, string $source)
    {
        $this->branch = $branch;
        $this->source = $source;
    }

    /** A branch taken from GITHOOKS_BRANCH, read until 5.0. */
    public static function fromLegacyEnvVar(string $branch): self
    {
        $resolution = new self($branch, 'env');
        $resolution->legacyEnvVar = true;

        return $resolution;
    }

    public function isFromLegacyEnvVar(): bool
    {
        return $this->legacyEnvVar;
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
