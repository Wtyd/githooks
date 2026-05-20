<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Wtyd\GitHooks\Utils\BranchResolver::resolve()} when none of
 * the cascade sources (CLI, env, CI vars, git) produced a usable branch name.
 *
 * The exception carries a pedagogical message that points the user at the two
 * escape hatches (`--branch=<name>` or `$GITHOOKS_BRANCH`); commands catch it
 * and surface the message verbatim before returning exit 1.
 */
class DetachedHeadException extends RuntimeException
{
}
