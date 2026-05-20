<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

use Wtyd\GitHooks\Utils\Exception\DetachedHeadException;

/**
 * Resolve the current branch from the canonical cascade (FEAT-2):
 *
 *   1. `$cliBranch` (e.g. `--branch=X` from the CLI command).
 *   2. `$GITHOOKS_BRANCH` env var — explicit user override, useful in custom
 *      automation that cannot rely on a known CI variable.
 *   3. CI variables in fixed order (GitLab → GitHub → Buildkite → Bitbucket →
 *      Circle → Drone → Travis). The order is documented; when several are
 *      simultaneously present, the higher-precedence one wins.
 *   4. `$fileUtils->getCurrentBranch()` — the raw `git rev-parse --abbrev-ref HEAD`.
 *
 * If all four steps yield nothing usable (detached HEAD: literal `HEAD` or
 * empty), throws {@see DetachedHeadException} with a pedagogical message
 * pointing the user at the two escape hatches.
 *
 * Stateless; safe to instantiate per invocation.
 */
class BranchResolver
{
    /**
     * Search order for CI env vars. Each entry maps the env var name to the
     * source label used in {@see BranchResolution::getSource()}. Travis emits
     * two vars depending on whether the build is a PR; the PR var is checked
     * first so that on a PR build the head branch wins over the target.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const CI_VARS = [
        ['CI_COMMIT_REF_NAME',         'ci:gitlab'],
        ['GITHUB_REF_NAME',            'ci:github'],
        ['BUILDKITE_BRANCH',           'ci:buildkite'],
        ['BITBUCKET_BRANCH',           'ci:bitbucket'],
        ['CIRCLE_BRANCH',              'ci:circle'],
        ['DRONE_COMMIT_BRANCH',        'ci:drone'],
        ['TRAVIS_PULL_REQUEST_BRANCH', 'ci:travis'],
        ['TRAVIS_BRANCH',              'ci:travis'],
    ];

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The cascade has 4 levels with
     *   guards on each — the complexity is the surface, not accidental nesting.
     */
    public function resolve(?string $cliBranch, FileUtilsInterface $fileUtils): BranchResolution
    {
        if ($cliBranch !== null && $cliBranch !== '') {
            return new BranchResolution($cliBranch, 'cli');
        }

        $envOverride = getenv('GITHOOKS_BRANCH');
        if (is_string($envOverride) && $envOverride !== '') {
            return new BranchResolution($envOverride, 'env');
        }

        foreach (self::CI_VARS as [$var, $source]) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                return new BranchResolution($value, $source);
            }
        }

        $gitBranch = $fileUtils->getCurrentBranch();
        if ($gitBranch !== '' && $gitBranch !== 'HEAD') {
            return new BranchResolution($gitBranch, 'git');
        }

        throw new DetachedHeadException(
            "Could not determine current branch: detached HEAD. "
            . "Use --branch=<name> or set the GITHOOKS_BRANCH environment variable."
        );
    }
}
