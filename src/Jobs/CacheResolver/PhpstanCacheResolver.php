<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs\CacheResolver;

/**
 * Resolves the effective tmpDir for a PHPStan configuration.
 *
 * Walks the includes: chain recursively (cycle-safe). The current file's own
 * tmpDir wins over its includes; among siblings the last include in load
 * order wins. Expands the two placeholders that show up in real-world neon
 * configs:
 *   - %currentWorkingDirectory% — process cwd
 *   - %rootDir% — directory of the .neon where the placeholder appears
 *
 * Other PHPStan placeholders (%env.X%, custom parameters, ...) are left
 * literal — the resolver is intentionally partial. If the resolved tmpDir
 * still contains an unexpanded placeholder, the caller is free to fall
 * back to the default.
 */
final class PhpstanCacheResolver
{
    /**
     * @return string|null Effective tmpDir with placeholders expanded, or null when no
     *                     tmpDir is declared in the include chain.
     */
    public static function resolve(string $configPath): ?string
    {
        $visited = [];
        return self::resolveRecursive($configPath, $visited);
    }

    /**
     * @param array<string, true> $visited
     */
    private static function resolveRecursive(string $configPath, array &$visited): ?string
    {
        if (!is_file($configPath) || !is_readable($configPath)) {
            return null;
        }
        $real = realpath($configPath);
        if ($real === false || isset($visited[$real])) {
            return null;
        }
        $visited[$real] = true;

        $content = file_get_contents($real);
        if ($content === false) {
            return null;
        }

        $tmpDir = null;

        foreach (self::extractIncludes($content) as $include) {
            $resolvedInclude = self::resolveIncludePath($real, $include);
            $childTmp = self::resolveRecursive($resolvedInclude, $visited);
            if ($childTmp !== null) {
                $tmpDir = $childTmp;
            }
        }

        $own = self::extractTmpDir($content);
        if ($own !== null) {
            $tmpDir = self::expandPlaceholders($own, dirname($real));
        }

        return $tmpDir;
    }

    /**
     * @return string[]
     */
    private static function extractIncludes(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $inIncludes = false;
        $includes = [];
        foreach ($lines as $line) {
            if (preg_match('/^includes:\s*$/', $line)) {
                $inIncludes = true;
                continue;
            }
            if (!$inIncludes) {
                continue;
            }
            if (preg_match('/^\s+-\s+(.+?)(?:\s*#.*)?$/', $line, $matches)) {
                $value = trim($matches[1], "\"'");
                if ($value !== '') {
                    $includes[] = $value;
                }
                continue;
            }
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $inIncludes = false;
        }
        return $includes;
    }

    /**
     * tmpDir is read only when nested directly under a top-level "parameters:" block.
     * NEON otherwise allows "tmpDir" as an arbitrary key inside services or other
     * sections — without context tracking we'd misread those as PHPStan's tmpDir.
     */
    private static function extractTmpDir(string $content): ?string
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $tmpDir = null;
        $insideParameters = false;

        foreach ($lines as $line) {
            if (self::isBlankOrComment($line)) {
                continue;
            }
            if (self::isTopLevelKey($line)) {
                $insideParameters = preg_match('/^parameters:\s*$/', $line) === 1;
                continue;
            }
            if ($insideParameters && preg_match('/^\s+tmpDir:\s*([^#\s].*?)\s*(?:#.*)?$/', $line, $matches)) {
                $tmpDir = trim($matches[1], "\"'");
            }
        }
        return $tmpDir;
    }

    private static function isBlankOrComment(string $line): bool
    {
        $trimmed = ltrim($line);
        return $trimmed === '' || $trimmed[0] === '#';
    }

    private static function isTopLevelKey(string $line): bool
    {
        return $line !== '' && !ctype_space($line[0]) && $line[0] !== "\t";
    }

    private static function resolveIncludePath(string $parentPath, string $include): string
    {
        if ($include === '') {
            return $include;
        }
        if ($include[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $include)) {
            return $include;
        }
        return dirname($parentPath) . DIRECTORY_SEPARATOR . $include;
    }

    private static function expandPlaceholders(string $value, string $configDir): string
    {
        $cwd = getcwd();
        return strtr($value, [
            '%currentWorkingDirectory%' => $cwd !== false ? $cwd : '.',
            '%rootDir%' => $configDir,
        ]);
    }
}
