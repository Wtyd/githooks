<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Output\Concerns;

/**
 * Converts absolute file paths within the current working directory to relative paths.
 *
 * Structured output formats (Code Climate, SARIF) expect paths relative to the
 * workspace root. Tool parsers sometimes emit absolute paths (phpcs, for example);
 * this trait normalises them. Paths outside the CWD and already-relative paths are
 * left untouched.
 */
trait RelativizesFilePath
{
    private function relativizePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false || $path === '' || $path[0] !== '/') {
            return $path;
        }
        $prefix = rtrim($cwd, '/') . '/';
        if (strpos($path, $prefix) === 0) {
            return substr($path, strlen($prefix));
        }
        return $path;
    }
}
