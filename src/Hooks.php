<?php

namespace GitHooks;

class Hooks
{
    /**
     * Supported Hooks. Are the same that supported hooks by Git (https://git-scm.com/docs/githooks).
     */
    public const HOOKS = [
        'applypatch-msg',
        'pre-applypatch',
        'post-applypatch',
        'pre-commit',
        'pre-merge-commit',
        'prepare-commit-msg',
        'commit-msg',
        'post-commit',
        'pre-rebase',
        'post-checkout',
        'post-merge',
        'pre-push',
        'pre-receive',
        'update',
        'proc-receive',
        'post-receive',
        'post-update',
        'reference-transaction',
        'push-to-checkout',
        'pre-auto-gc',
        'post-rewrite',
        'sendemail-validate',
        'fsmonitor-watchman',
        'p4-changelist',
        'p4-prepare-changelist',
        'p4-post-changelist',
        'p4-pre-submit',
        'post-index-change',
    ];

    /**
     * Checks if $hook is a supported hook.
     *
     * @param string $hook The hook that will be checked.
     *
     * @return bool
     */
    public static function validate(string $hook)
    {
        return in_array($hook, self::HOOKS);
    }
}
