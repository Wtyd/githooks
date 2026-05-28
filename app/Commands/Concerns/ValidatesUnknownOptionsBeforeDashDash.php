<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\App\Commands\Concerns;

use ReflectionClass;
use ReflectionException;

/**
 * Validates that no unknown CLI options appear before the POSIX `--` separator.
 *
 * `JobCommand` keeps `ignoreValidationErrors()` enabled so that tokens after
 * `--` (passthrough to the underlying QA tool) survive Symfony's input
 * validation. The side effect is that unknown options anywhere are silently
 * dropped — including typos of legitimate githooks options. That regression
 * showed up as `--foo=bar --config=X` losing `--config` silently (BUG-21).
 *
 * `FlowCommand` and `FlowsCommand` keep `ignoreValidationErrors()` purely so
 * that Symfony does not throw mid-binding on a typo; they delegate the actual
 * detection to this concern (and emit a separate custom error if `--` itself
 * is present, since neither supports passthrough).
 *
 * Reads tokens from `$this->input` via reflection on the underlying
 * `ArgvInput`/`StringInput` `$tokens` property. This is necessary because:
 *
 *  - In production the binary uses `ArgvInput`, whose `$tokens` reflects the
 *    real command line.
 *  - In tests Laravel-Zero's `$this->artisan('flow qa …')` is parsed into a
 *    `StringInput` (the test command string), whose `$tokens` reflects what
 *    the test actually typed — *not* `$_SERVER['argv']`, which carries the
 *    parent phpunit's own flags (`--colors=always`, `--log-junit=junit.xml`, …)
 *    and would otherwise generate false-positive errors in every flow/flows
 *    test.
 *
 * If the input is not introspectable (no `tokens` property), the concern
 * defaults to permissive — returns `true` — to avoid breaking unrelated
 * downstream Input implementations.
 */
trait ValidatesUnknownOptionsBeforeDashDash
{
    /**
     * @return bool true when every option-looking token before `--` matches a
     *              declared long option or short shortcut on the command.
     */
    protected function assertNoUnknownOptionsBeforeDashDash(): bool
    {
        $args = $this->extractArgsBeforeDashDash();

        $unknown = [];
        foreach ($args as $token) {
            if (!is_string($token) || $token === '' || $token[0] !== '-') {
                continue;
            }

            if (strpos($token, '--') === 0) {
                $name = substr($token, 2);
                if ($name === '') {
                    continue;
                }
                $equalsPos = strpos($name, '=');
                if ($equalsPos !== false) {
                    $name = substr($name, 0, $equalsPos);
                }
                if ($name === '' || $this->getDefinition()->hasOption($name)) {
                    continue;
                }
                $unknown[] = '--' . $name;
                continue;
            }

            // Short option(s): clustered (-xyz ≡ -x -y -z). Strip a stray `=value` defensively.
            $body = substr($token, 1);
            $equalsPos = strpos($body, '=');
            if ($equalsPos !== false) {
                $body = substr($body, 0, $equalsPos);
            }
            foreach (str_split($body) as $char) {
                if (!$this->getDefinition()->hasShortcut($char)) {
                    $unknown[] = '-' . $char;
                }
            }
        }

        $unknown = array_values(array_unique($unknown));

        if (empty($unknown)) {
            return true;
        }

        foreach ($unknown as $opt) {
            $this->error(sprintf('The "%s" option does not exist.', $opt));
        }

        return false;
    }

    /**
     * @return bool true if `--` appears as a token in the input.
     */
    protected function inputContainsDashDashSeparator(): bool
    {
        $tokens = $this->getInputTokens();
        // `tokens` excludes the command name as the first element for ArgvInput
        // but includes it for StringInput. Either way, `--` is just a value to
        // look up — checking the full array is safe.
        return in_array('--', $tokens, true);
    }

    /**
     * Slice the input tokens to everything that lives *before* `--`, with the
     * leading command-name token dropped. Returns positional args and options
     * intermixed — callers filter by leading `-`.
     *
     * @return string[]
     */
    private function extractArgsBeforeDashDash(): array
    {
        $tokens = $this->getInputTokens();
        if (empty($tokens)) {
            return [];
        }

        // Drop the command name (first token).
        $args = array_slice($tokens, 1);
        $dashDashIndex = array_search('--', $args, true);
        if ($dashDashIndex === false) {
            return $args;
        }
        return array_slice($args, 0, $dashDashIndex);
    }

    /**
     * Pull the raw tokens out of the underlying `ArgvInput`/`StringInput` via
     * reflection. Returns an empty array if the property is unavailable.
     *
     * @return string[]
     */
    private function getInputTokens(): array
    {
        if (!isset($this->input)) {
            return [];
        }
        try {
            // `tokens` lives on Symfony's `ArgvInput`, but `$this->input` may be a
            // subclass (`StringInput` extends `ArgvInput`) and PHP's
            // `ReflectionProperty($obj, $name)` only sees properties declared on
            // the exact class — not inherited ones. Walk up the hierarchy until
            // we find a class that declares `tokens`.
            $class = new ReflectionClass($this->input);
            while ($class !== false) {
                if ($class->hasProperty('tokens')) {
                    $prop = $class->getProperty('tokens');
                    $prop->setAccessible(true);
                    $tokens = $prop->getValue($this->input);
                    return is_array($tokens) ? $tokens : [];
                }
                $class = $class->getParentClass();
            }
            return [];
        } catch (ReflectionException $e) {
            return [];
        }
    }
}
