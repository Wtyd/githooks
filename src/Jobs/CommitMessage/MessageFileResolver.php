<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs\CommitMessage;

/**
 * Resolves the path of the commit-message file from the available sources, in
 * priority order (REQ-005, FEAT-16):
 *
 *   1. explicit path — Git hook arg `$1` or `--message-file=PATH`
 *   2. inline string — `--message=STRING` (materialised to a temp file)
 *   3. environment   — `GITHOOKS_COMMIT_MSG_FILE` (only consulted via flow/flows)
 *   4. fallback      — `<rootPath>/.git/COMMIT_EDITMSG`
 *
 * `resolve()` returns a path candidate, or `null` only when no explicit/inline/
 * env source was given AND the fallback file does not exist — the caller maps
 * `null` to the "no message file available" error (REQ-005). An explicit path
 * that turns out to be unreadable is returned as-is so the caller surfaces the
 * "cannot read message file" error instead (REQ-006).
 *
 * Disk access is isolated behind protected seams so the resolver is unit-tested
 * without touching the filesystem (the convention established by CpuDetector /
 * MemoryDetector).
 */
class MessageFileResolver
{
    public function resolve(
        ?string $explicitFile,
        ?string $inlineContent,
        ?string $envFile,
        string $rootPath
    ): ?string {
        if ($explicitFile !== null && $explicitFile !== '') {
            return $explicitFile;
        }

        if ($inlineContent !== null) {
            return $this->writeTemp($inlineContent);
        }

        if ($envFile !== null && $envFile !== '') {
            return $envFile;
        }

        $fallback = rtrim($rootPath, '/') . '/.git/COMMIT_EDITMSG';
        return $this->fileExists($fallback) ? $fallback : null;
    }

    /**
     * Read the raw bytes of the resolved file, or null when it does not exist
     * or is not readable. Normalization (BOM, CRLF) and subject extraction are
     * the validator's job ({@see CommitMessageValidator::extractSubject()}).
     */
    public function readRaw(string $path): ?string
    {
        if (!$this->fileExists($path)) {
            return null;
        }
        $contents = $this->readContents($path);
        return $contents === false ? null : $contents;
    }

    protected function fileExists(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }

    /**
     * @return string|false
     */
    protected function readContents(string $path)
    {
        return file_get_contents($path);
    }

    /**
     * Materialise an inline `--message` string to a temp file and return its
     * path, so the rest of the pipeline treats every source uniformly as a path.
     */
    protected function writeTemp(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'githooks-commit-msg-');
        file_put_contents($path, $content);
        return $path;
    }
}
