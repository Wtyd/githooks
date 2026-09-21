<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Utils;

class Platform
{
    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Absolute on POSIX (`/…`) or Windows (`C:\…`, `C:/…`).
     */
    public static function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1);
    }

    public static function normalizePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Redirect stderr to null, cross-platform.
     */
    public static function stderrRedirect(): string
    {
        return self::isWindows() ? '2>NUL' : '2>/dev/null';
    }
}
