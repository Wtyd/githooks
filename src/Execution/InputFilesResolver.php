<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Execution;

use Wtyd\GitHooks\Execution\Exception\InputFilesException;
use Wtyd\GitHooks\Hooks\PatternMatcher;
use Wtyd\GitHooks\Utils\FileUtilsInterface;

/**
 * Resolve user-provided file lists (--files, --files-from, --exclude-pattern)
 * into an InputFilesResolution that the rest of the pipeline can consume.
 *
 * Pure orchestrator: all glob/match logic is delegated to PatternMatcher and
 * directory expansion is delegated to FileUtilsInterface so tests can swap
 * fakes without touching the filesystem.
 *
 * Reference: spec-design-files-flag.md §3.1, §3.1.1, §3.2.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Centralises every spec branch in one place.
 */
class InputFilesResolver
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /** @var string[] Default extensions for directory expansion (REQ-013). */
    private const DEFAULT_EXTENSIONS = ['php', 'phtml'];

    private FileUtilsInterface $fileUtils;

    private PatternMatcher $patternMatcher;

    public function __construct(FileUtilsInterface $fileUtils, PatternMatcher $patternMatcher)
    {
        $this->fileUtils      = $fileUtils;
        $this->patternMatcher = $patternMatcher;
    }

    /**
     * Resolve user-provided flags into an InputFilesResolution. Throws on every
     * spec-defined fatal condition (mutual exclusion, missing manifest, all
     * invalid, exclude eliminates all, etc.).
     *
     * @param string[] $extensions
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each spec rule is one branch.
     * @SuppressWarnings(PHPMD.NPathComplexity) Same reason — guards short-circuit.
     */
    public function resolve(
        ?string $filesOption,
        ?string $filesFromOption,
        ?string $excludePatternOption,
        string $cwd,
        array $extensions = self::DEFAULT_EXTENSIONS
    ): InputFilesResolution {
        $files       = $this->normaliseScalar($filesOption);
        $filesFrom   = $this->normaliseScalar($filesFromOption);
        $excludeRaw  = $this->normaliseScalar($excludePatternOption);

        if ($files !== null && $filesFrom !== null) {
            throw InputFilesException::mutuallyExclusive();
        }

        if ($excludeRaw !== null && $files === null && $filesFrom === null) {
            throw InputFilesException::excludePatternRequiresInput();
        }

        if ($files === null && $filesFrom === null) {
            // Empty CLI value, e.g. `--files=`, after normaliseScalar.
            throw InputFilesException::emptyInput();
        }

        $bomDetected = false;
        if ($filesFrom !== null) {
            [$rawList, $bomDetected] = $this->readManifest($filesFrom);
            $source     = InputFilesResolution::SOURCE_FILES_FROM;
            $sourcePath = $filesFrom;
        } else {
            $rawList    = $this->parseCsv((string) $files);
            $source     = InputFilesResolution::SOURCE_CLI;
            $sourcePath = null;
        }

        if (empty($rawList)) {
            throw InputFilesException::emptyInput();
        }

        $totalProvided = count($rawList);

        [$expanded, $invalid] = $this->expandAndValidate($rawList, $cwd, $extensions);

        if (empty($expanded)) {
            throw InputFilesException::allInvalid();
        }

        $excludePatterns = $excludeRaw !== null ? $this->parseCsv($excludeRaw) : [];

        [$kept, $excluded] = $this->applyExcludePatterns($expanded, $excludePatterns);

        if (!empty($excludePatterns) && empty($kept)) {
            throw InputFilesException::excludeEliminatedAll();
        }

        return new InputFilesResolution(
            $source,
            $sourcePath,
            $kept,
            $invalid,
            $excludePatterns,
            $excluded,
            $totalProvided,
            $bomDetected
        );
    }

    private function normaliseScalar(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return string[] CSV → trimmed, deduplicated, non-empty entries.
     */
    private function parseCsv(string $csv): array
    {
        $parts = explode(',', $csv);
        $out   = [];
        $seen  = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || isset($seen[$part])) {
                continue;
            }
            $seen[$part] = true;
            $out[] = $part;
        }
        return $out;
    }

    /**
     * Read a --files-from manifest. Strips UTF-8 BOM, ignores blank lines and
     * `#` comments, normalises CRLF, deduplicates while preserving order.
     *
     * @return array{0: string[], 1: bool} [list, bomDetected]
     */
    private function readManifest(string $path): array
    {
        if (!is_file($path)) {
            throw InputFilesException::filesFromMissing($path);
        }

        $contents = (string) file_get_contents($path);

        $bomDetected = false;
        if (strpos($contents, self::UTF8_BOM) === 0) {
            $contents    = substr($contents, strlen(self::UTF8_BOM));
            $bomDetected = true;
        }

        $contents = str_replace("\r\n", "\n", $contents);
        $lines    = explode("\n", $contents);

        $out  = [];
        $seen = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $out[] = $line;
        }

        return [$out, $bomDetected];
    }

    /**
     * For each raw entry: resolve against CWD (unless absolute), validate
     * existence, expand directories with the provided extensions. The returned
     * paths preserve the user's perspective: absolute if the input was
     * absolute, relative-to-CWD if it was relative (REQ-006/REQ-034).
     *
     * @param string[] $rawList
     * @param string[] $extensions
     * @return array{0: string[], 1: string[]} [expanded, invalid]
     */
    private function expandAndValidate(array $rawList, string $cwd, array $extensions): array
    {
        $expanded = [];
        $invalid  = [];
        $seen     = [];

        foreach ($rawList as $raw) {
            $wasAbsolute = $this->isAbsolute($raw);
            $resolved    = $this->resolveAgainstCwd($raw, $cwd);

            if (is_dir($resolved)) {
                $files = $this->fileUtils->expandDirectory($resolved, $extensions);
                foreach ($files as $file) {
                    $shaped = $this->shapeForUser($file, $cwd, $wasAbsolute);
                    if (isset($seen[$shaped])) {
                        continue;
                    }
                    $seen[$shaped] = true;
                    $expanded[] = $shaped;
                }
                continue;
            }

            if (is_file($resolved)) {
                $shaped = $this->shapeForUser($resolved, $cwd, $wasAbsolute);
                if (isset($seen[$shaped])) {
                    continue;
                }
                $seen[$shaped] = true;
                $expanded[] = $shaped;
                continue;
            }

            $invalid[] = $raw;
        }

        return [$expanded, $invalid];
    }

    /**
     * Make the resolved path match the user's frame of reference: absolute when
     * the entry was absolute, CWD-relative otherwise.
     */
    private function shapeForUser(string $absolutePath, string $cwd, bool $wasAbsolute): string
    {
        $normalised = $this->normalisePath($absolutePath);
        if ($wasAbsolute) {
            return $normalised;
        }
        $cwdPrefix = rtrim($this->normalisePath($cwd), '/') . '/';
        if (strpos($normalised, $cwdPrefix) === 0) {
            return substr($normalised, strlen($cwdPrefix));
        }
        return $normalised;
    }

    /**
     * Apply --exclude-pattern (OR semantics: drop file if ANY pattern matches).
     *
     * @param string[] $files
     * @param string[] $patterns
     * @return array{0: string[], 1: string[]} [kept, excluded]
     */
    private function applyExcludePatterns(array $files, array $patterns): array
    {
        if (empty($patterns)) {
            return [$files, []];
        }

        $kept     = [];
        $excluded = [];

        foreach ($files as $file) {
            $matchesAny = false;
            foreach ($patterns as $pattern) {
                if ($this->patternMatcher->fileMatchesPattern($file, $pattern)) {
                    $matchesAny = true;
                    break;
                }
            }

            if ($matchesAny) {
                $excluded[] = $file;
            } else {
                $kept[] = $file;
            }
        }

        return [$kept, $excluded];
    }

    private function resolveAgainstCwd(string $path, string $cwd): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }
        return rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        // Windows drive letter (C:\..)
        if (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\')) {
            return true;
        }
        return false;
    }

    private function normalisePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
