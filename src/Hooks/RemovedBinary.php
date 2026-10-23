<?php

declare(strict_types=1);

namespace Wtyd\Alfil\Hooks;

/**
 * The binary was called `githooks` until 4.0 and is `alfil` since. A hook
 * script installed by 3.x, or a `hooks.command` written for it, still calls
 * the old name, which no longer exists after the package change: every
 * trigger then fails until the scripts are regenerated with `alfil hook`.
 */
final class RemovedBinary
{
    public const NAME = 'githooks';

    /**
     * Whether $text runs the removed binary: `githooks` as a word preceded by
     * the start of the line, whitespace or a path separator (so
     * `vendor/bin/githooks` and `php7.4 githooks` count, `.githooks/` and
     * `githooks.php` do not). Shell comment lines are ignored.
     */
    public static function isCalledBy(string $text): bool
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (strpos(ltrim($line), '#') === 0) {
                continue;
            }
            if (preg_match('/(^|[\s\/])githooks(\s|$)/', $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
