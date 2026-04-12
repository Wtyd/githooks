<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Hooks;

/**
 * Stateless matcher for branch names and file paths against glob patterns.
 *
 * Supports:
 * - Single star (*): matches anything except /
 * - Double star (**): matches zero or more directories
 * - Question mark (?): matches one character except /
 */
class PatternMatcher
{
    /**
     * Check if a branch matches the include/exclude patterns.
     * Empty include patterns = match all. Exclude always prevails.
     *
     * @param string[] $includePatterns
     * @param string[] $excludePatterns
     */
    public function matchesBranch(string $branch, array $includePatterns, array $excludePatterns = []): bool
    {
        if ($branch === '') {
            return false;
        }

        $matched = empty($includePatterns);
        foreach ($includePatterns as $pattern) {
            if ($branch === $pattern || fnmatch($pattern, $branch)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return false;
        }

        foreach ($excludePatterns as $pattern) {
            if ($branch === $pattern || fnmatch($pattern, $branch)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any staged file matches include patterns and is not excluded.
     *
     * @param string[] $files
     * @param string[] $includePatterns
     * @param string[] $excludePatterns
     */
    public function matchesFiles(array $files, array $includePatterns, array $excludePatterns = []): bool
    {
        foreach ($files as $file) {
            $matched = empty($includePatterns);
            foreach ($includePatterns as $pattern) {
                if ($this->fileMatchesPattern($file, $pattern)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $excluded = false;
            foreach ($excludePatterns as $pattern) {
                if ($this->fileMatchesPattern($file, $pattern)) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match a file path against a glob pattern. Supports ** for recursive directory matching.
     * Without **, uses fnmatch with FNM_PATHNAME (* does not cross /).
     */
    public function fileMatchesPattern(string $file, string $pattern): bool
    {
        if (strpos($pattern, '**') === false) {
            return fnmatch($pattern, $file, FNM_PATHNAME);
        }

        return (bool) preg_match($this->globToRegex($pattern), $file);
    }

    /**
     * Convert a glob pattern with double-star support to a regex.
     *
     * Supports: double-star between slashes (zero or more dirs), double-star at end
     * (everything below), single star (anything except /), ? (one char except /).
     */
    public function globToRegex(string $pattern): string
    {
        $segments = explode('**', $pattern);

        $regexSegments = array_map(function (string $seg): string {
            return strtr(preg_quote($seg, '#'), [
                '\\*' => '[^/]*',
                '\\?' => '[^/]',
            ]);
        }, $segments);

        $regex = $regexSegments[0];
        for ($i = 1, $count = count($regexSegments); $i < $count; $i++) {
            $right = $regexSegments[$i];
            $leftEndsSlash = substr($regex, -1) === '/';
            $rightStartsSlash = isset($right[0]) && $right[0] === '/';

            if ($leftEndsSlash && $rightStartsSlash) {
                $regex = substr($regex, 0, -1) . '(?:/.+/|/)' . substr($right, 1);
            } elseif ($leftEndsSlash) {
                $regex .= '.*' . $right;
            } elseif ($rightStartsSlash) {
                $regex .= '(?:.*/)?' . substr($right, 1);
            } else {
                $regex .= '.*' . $right;
            }
        }

        return '#^' . $regex . '$#';
    }
}
