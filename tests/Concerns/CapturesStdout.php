<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Captures stdout produced by code that emits via `echo` directly
 * (decorators in src/Output/CI/* and friends bypass any injectable
 * OutputInterface). Encapsulates the ob_start / ob_get_clean dance so
 * each test reads as "what I want to capture", not as buffer plumbing.
 *
 * The captured output is returned with GitLab section_start/end ANSI
 * prefixes (`\033[0K`) stripped — those are emit-time control sequences
 * that always pair around the real content, so they only get in the way
 * when asserting against the body.
 */
trait CapturesStdout
{
    /**
     * Execute the callable with stdout captured. Returns the captured
     * string with `\033[0K` ANSI prefixes stripped.
     *
     * @param callable $action arbitrary code that emits via `echo`
     */
    protected function captureStdout(callable $action): string
    {
        ob_start();
        try {
            $action();
        } finally {
            $raw = ob_get_clean();
        }
        $raw = $raw === false ? '' : $raw;
        return preg_replace('/\033\[0K/', '', $raw) ?? '';
    }

    /**
     * Capture the raw stdout without stripping any ANSI sequence. Useful
     * when the test cares about the framing (section_start/end markers,
     * coloured spans) instead of the body content.
     */
    protected function captureStdoutRaw(callable $action): string
    {
        ob_start();
        try {
            $action();
        } finally {
            $raw = ob_get_clean();
        }
        return $raw === false ? '' : $raw;
    }
}
