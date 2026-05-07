<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs\CacheResolver;

/**
 * Static-best-effort cache path extractor for PHP config files (rector.php,
 * .php-cs-fixer.php).
 *
 * These tools store cache config inside PHP code, which is not statically
 * parseable in the general case. We cover the three patterns that show up
 * in real projects:
 *
 *   $config->method('absolute/path')                      → 'absolute/path'
 *   $config->method(__DIR__ . '/literal')                 → dir(configFile)/literal
 *   $config->method(\sys_get_temp_dir() . '/literal')     → sys_get_temp_dir()/literal
 *
 * Anything dynamic (variables, helpers, env vars) is not extractable. The
 * caller distinguishes "match" (returns a string) from "no match"
 * (returns null) — when null, falling back to a default and emitting a
 * "could not parse" warning is up to the caller.
 *
 * The resolver intentionally does not load or execute the config file.
 */
final class PhpConfigCacheResolver
{
    private const STRING_PATTERN = '(?:\'((?:\\\\.|[^\'\\\\])*)\'|"((?:\\\\.|[^"\\\\])*)")';

    /**
     * @return string|null Resolved cache path, or null when no recognized
     *                     pattern matches (or the file is unreadable).
     */
    public static function resolve(string $configPath, string $methodName): ?string
    {
        $content = self::readSanitizedContent($configPath);
        if ($content === null) {
            return null;
        }
        $methodEscaped = preg_quote($methodName, '/');

        $allCallOffsets = self::allInvocationOffsets($content, $methodEscaped);
        if ($allCallOffsets === []) {
            return null;
        }
        $lastCallOffset = max($allCallOffsets);

        $candidates = array_merge(
            self::collectDirConcat($content, $methodEscaped, $configPath),
            self::collectSysTempConcat($content, $methodEscaped),
            self::collectLiteral($content, $methodEscaped)
        );

        foreach ($candidates as $hit) {
            if ($hit['pos'] === $lastCallOffset) {
                return $hit['path'];
            }
        }
        return null;
    }

    /** @return int[] */
    private static function allInvocationOffsets(string $content, string $methodEscaped): array
    {
        if (preg_match_all('/->\s*' . $methodEscaped . '\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $offsets = [];
        foreach ($matches[0] as $match) {
            $offsets[] = (int) $match[1];
        }
        return $offsets;
    }

    /**
     * Reads the file and strips PHP comments so commented-out method calls
     * don't pollute the regex match.
     */
    private static function readSanitizedContent(string $configPath): ?string
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            return null;
        }
        $content = file_get_contents($configPath);
        if ($content === false) {
            return null;
        }
        return self::stripComments($content);
    }

    private static function stripComments(string $content): string
    {
        $tokens = token_get_all($content);
        if ($tokens === []) {
            return $content;
        }
        $out = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /** @return array<int, array{pos: int, path: string}> */
    private static function collectDirConcat(string $content, string $methodEscaped, string $configPath): array
    {
        $regex = '/->\s*' . $methodEscaped . '\s*\(\s*__DIR__\s*\.\s*' . self::STRING_PATTERN . '\s*\)/';
        $base = dirname(realpath($configPath) ?: $configPath);
        return self::collectMatches($regex, $content, static function (array $m) use ($base): string {
            return $base . self::pickLiteral($m);
        });
    }

    /** @return array<int, array{pos: int, path: string}> */
    private static function collectSysTempConcat(string $content, string $methodEscaped): array
    {
        $regex = '/->\s*' . $methodEscaped . '\s*\(\s*\\\\?sys_get_temp_dir\s*\(\s*\)\s*\.\s*' . self::STRING_PATTERN . '\s*\)/';
        $tmp = sys_get_temp_dir();
        return self::collectMatches($regex, $content, static function (array $m) use ($tmp): string {
            return $tmp . self::pickLiteral($m);
        });
    }

    /** @return array<int, array{pos: int, path: string}> */
    private static function collectLiteral(string $content, string $methodEscaped): array
    {
        $regex = '/->\s*' . $methodEscaped . '\s*\(\s*' . self::STRING_PATTERN . '\s*\)/';
        return self::collectMatches($regex, $content, static function (array $m): string {
            return self::pickLiteral($m);
        });
    }

    /**
     * @param callable(array<int, string>): string $resolver
     * @return array<int, array{pos: int, path: string}>
     */
    private static function collectMatches(string $regex, string $content, callable $resolver): array
    {
        if (preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $hits = [];
        $count = isset($matches[0]) ? count($matches[0]) : 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = (int) $matches[0][$i][1];
            $captured = [
                $matches[0][$i][0],
                isset($matches[1][$i]) ? (string) $matches[1][$i][0] : '',
                isset($matches[2][$i]) ? (string) $matches[2][$i][0] : '',
            ];
            $hits[] = ['pos' => $offset, 'path' => $resolver($captured)];
        }
        return $hits;
    }

    /** @param array<int, string> $matches */
    private static function pickLiteral(array $matches): string
    {
        return $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');
    }

    /**
     * Whether the config file declares the given method but with an expression
     * that we can't resolve statically (variables, function calls other than
     * __DIR__/sys_get_temp_dir, env vars, ...). Used by callers to decide
     * whether to surface a "could not parse" warning.
     */
    public static function declaresUnresolvable(string $configPath, string $methodName): bool
    {
        $content = self::readSanitizedContent($configPath);
        if ($content === null) {
            return false;
        }
        $methodEscaped = preg_quote($methodName, '/');
        return preg_match('/->\s*' . $methodEscaped . '\s*\(/', $content) === 1
            && self::resolve($configPath, $methodName) === null;
    }
}
