<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\CI;

class CIEnvironment
{
    public const GITHUB_ACTIONS = 'github-actions';
    public const GITLAB_CI = 'gitlab-ci';
    public const NONE = 'none';

    public static function detect(): string
    {
        if (getenv('GITHUB_ACTIONS') === 'true') {
            return self::GITHUB_ACTIONS;
        }

        if (getenv('GITLAB_CI') !== false) {
            return self::GITLAB_CI;
        }

        return self::NONE;
    }
}
