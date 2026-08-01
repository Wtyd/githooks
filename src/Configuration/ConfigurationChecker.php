<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Configuration;

use Wtyd\GitHooks\Jobs\JobAbstract;

/**
 * Pure validation logic extracted from CheckConfigurationFileCommand (Phase 3b).
 *
 * These checks were embedded as private helpers on the Command, which forced
 * the 25 conf:check system tests (~7.5s in artisan harness) to be the only
 * cover for each rule. The class is dependency-free and side-effect-free
 * except for filesystem reads (file_exists, is_dir, exec('which …')), so
 * each rule can be unit-tested with synthetic fixtures.
 *
 * The Command keeps the rendering side (table layout, color tags); the
 * checker returns plain warning strings the Command can format.
 */
class ConfigurationChecker
{
    /**
     * Collect all warnings for a single job: executable path resolution,
     * declared `paths`, and referenced config / rules files.
     *
     * Empty result → the job is valid.
     *
     * @return string[]
     */
    public function validateJob(JobAbstract $jobInstance, JobConfiguration $jobConfig): array
    {
        $warnings = [];

        $executableWarning = $this->validateExecutable($jobInstance->getExecutable());
        if ($executableWarning !== null) {
            $warnings[] = $executableWarning;
        }

        foreach ($this->validatePaths($jobConfig->getPaths()) as $pathWarning) {
            $warnings[] = $pathWarning;
        }

        foreach ($this->validateConfigFiles($jobConfig->getConfig()) as $fileWarning) {
            $warnings[] = $fileWarning;
        }

        return $warnings;
    }

    /**
     * Returns a "executable 'X' not found" warning when the executable is
     * declared but cannot be found on disk nor on `$PATH`. Returns null when
     * the executable resolves (or is the empty string, which means
     * auto-detection).
     */
    public function validateExecutable(string $executable): ?string
    {
        if ($executable === '') {
            return null;
        }
        if ($this->executableExists($executable)) {
            return null;
        }
        return "executable '$executable' not found";
    }

    /**
     * One warning per declared path that does not exist on disk (neither as
     * a directory nor as a file).
     *
     * @param string[] $paths
     * @return string[]
     */
    public function validatePaths(array $paths): array
    {
        $warnings = [];
        foreach ($paths as $path) {
            if (!is_dir($path) && !file_exists($path)) {
                $warnings[] = "path '$path' not found";
            }
        }
        return $warnings;
    }

    /**
     * Validate the `config` and `rules` file references inside a job's
     * argument bag. `rules` is only treated as a filesystem path when it
     * looks like one (contains `/` or `.xml`) — symbolic rule lists like
     * `cleancode,codesize` are left untouched.
     *
     * @param array<string, mixed> $jobArgs
     * @return string[]
     */
    public function validateConfigFiles(array $jobArgs): array
    {
        $warnings = [];

        if ($this->shouldCheckArg($jobArgs, 'config')) {
            $value = (string) $jobArgs['config'];
            if (!file_exists($value)) {
                $warnings[] = "config file '$value' not found";
            }
        }

        if (
            !empty($jobArgs['rules']) && is_string($jobArgs['rules'])
            && (strpos($jobArgs['rules'], '/') !== false || strpos($jobArgs['rules'], '.xml') !== false)
        ) {
            $value = (string) $jobArgs['rules'];
            if (!file_exists($value)) {
                $warnings[] = "rules file '$value' not found";
            }
        }

        return $warnings;
    }

    /**
     * Validate filesystem-level concerns of a `reports` map. Returns errors
     * (non-writable existing file or non-writable parent dir) and warnings
     * (missing parent dir; auto-created on run).
     *
     * @param array<string, string> $reports
     * @param string $context e.g. 'flows.options' or 'flows.qa.options'
     * @return array{errors: string[], warnings: string[]}
     */
    public function validateReportsPaths(array $reports, string $context): array
    {
        $errors = [];
        $warnings = [];
        foreach ($reports as $format => $path) {
            $where = "$context.reports.$format";

            if (file_exists($path) && !is_writable($path)) {
                $errors[] = "$where: '$path' is not writable.";
                continue;
            }

            $dir = dirname($path);
            if ($dir === '' || $dir === '.') {
                continue;
            }
            if (!is_dir($dir)) {
                $warnings[] = "$where: directory '$dir' does not exist; it will be created on run.";
                continue;
            }
            if (!is_writable($dir)) {
                $errors[] = "$where: directory '$dir' is not writable.";
            }
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Truncate commands longer than $maxLength chars for table readability.
     */
    public function truncateCommand(string $command, int $maxLength = 80): string
    {
        if (strlen($command) <= $maxLength) {
            return $command;
        }

        return substr($command, 0, $maxLength - 3) . '...';
    }

    /**
     * True when the executable resolves on disk or on `$PATH`.
     */
    public function executableExists(string $executable): bool
    {
        if (file_exists($executable)) {
            return true;
        }

        // A declared executable can be a command line rather than a filename —
        // `php artisan` (Pest's artisan runner), `sh -c`, `npm run`. Only the
        // first token is the binary; passing the whole string to `which` never
        // resolves, which reported every such job as broken. The remaining
        // tokens are the tool's own arguments and are not ours to validate:
        // guessing which of them is a file produces false positives
        // (`npm run lint`), so resolution stops at the first token.
        $binary = $this->firstToken($executable);
        if ($binary === '') {
            return false;
        }
        if ($binary !== $executable && file_exists($binary)) {
            return true;
        }

        $output = [];
        $code = 0;
        exec('which ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }

    /**
     * First whitespace-separated token of a declared executable, or the empty
     * string when there is none.
     */
    private function firstToken(string $executable): string
    {
        $tokens = preg_split('/\s+/', trim($executable), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) && $tokens !== [] ? $tokens[0] : '';
    }

    /**
     * @param array<string, mixed> $args
     */
    private function shouldCheckArg(array $args, string $key): bool
    {
        return !empty($args[$key]) && is_string($args[$key]);
    }
}
