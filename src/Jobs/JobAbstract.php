<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use LogicException;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\MemoryThreshold;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Execution\ThreadCapability;

/**
 * Base class for all job types. Subclasses declare ARGUMENT_MAP and the base
 * buildCommand() produces the CLI string. Only truly exceptional tools need
 * to override buildCommand().
 *
 * ARGUMENT_MAP entry format:
 *   'configKey' => ['flag' => '--flag', 'type' => 'value|boolean|paths|csv|repeat|key_value']
 *
 * Types:
 *   value     — --flag=value or -f value  (uses 'separator' => '=' or ' ')
 *   boolean   — --flag (present when truthy, omitted when falsy)
 *   paths     — space-separated list appended at the end
 *   csv       — --flag=a,b,c
 *   repeat    — --flag a --flag b (flag repeated per value)
 *   key_value — --key=value (flag equals the config key name)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Base for every job type: config parsing + args consumption + ARGUMENT_MAP dispatch + threading + structured output
 */
abstract class JobAbstract
{
    protected const ARGUMENT_MAP = [];

    protected string $name;

    protected string $type;

    protected string $executable;

    /** @var array<string, mixed> */
    protected array $args;

    protected bool $ignoreErrorsOnExit;

    protected bool $failFast;

    protected string $executablePrefix = '';

    protected string $cliExtraArguments = '';

    protected ?ExecutionContext $context = null;

    protected ?int $coresOverride = null;

    protected ?int $warnAfter = null;

    protected ?int $failAfter = null;

    protected ?int $memoryReserve = null;

    protected ?MemoryThreshold $memoryThreshold = null;

    public function __construct(JobConfiguration $config)
    {
        $this->name = $config->getName();
        $this->type = $config->getType();
        $this->args = $config->getConfig();
        $this->executable = $this->args['executable-path'] ?? $this->resolveExecutable();
        unset($this->args['executable-path']);
        $this->ignoreErrorsOnExit = (bool) ($this->args['ignore-errors-on-exit'] ?? false);
        unset($this->args['ignore-errors-on-exit']);
        $this->failFast = (bool) ($this->args['fail-fast'] ?? false);
        unset($this->args['fail-fast']);
        $this->coresOverride = $this->extractCoresOverride();
        $this->warnAfter = $this->extractPositiveInt('warn-after');
        $this->failAfter = $this->extractPositiveInt('fail-after');
        $this->memoryReserve = $config->getMemoryReserve();
        $this->memoryThreshold = $config->getMemoryThreshold();
        unset($this->args['memory']);
    }

    /**
     * Pop a positive-integer key from $this->args (or null when absent/invalid).
     */
    private function extractPositiveInt(string $key): ?int
    {
        if (!array_key_exists($key, $this->args)) {
            return null;
        }
        $value = $this->args[$key];
        unset($this->args[$key]);
        return is_int($value) && $value >= 1 ? $value : null;
    }

    /**
     * Resolve the cores override that the budget allocator pins for this job.
     *
     * Two equivalent declarations are accepted (only the first one wins when
     * both are present):
     *
     *   1. `cores: N` — the explicit, tool-agnostic form. Always honoured,
     *      regardless of the job type.
     *   2. The tool's native threading flag (e.g. `parallel: N` on phpcs,
     *      `threads: N` on psalm, `jobs: N` on parallel-lint, `processes: N`
     *      on paratest). Promoted as implicit override only when the
     *      capability is controllable. Uncontrollable (phpstan) and
     *      single-threaded tools have no native flag to promote, so the
     *      override stays null.
     *
     * Returns the validated positive integer, or null when absent/invalid.
     */
    private function extractCoresOverride(): ?int
    {
        if (array_key_exists('cores', $this->args)) {
            $cores = $this->args['cores'];
            unset($this->args['cores']);
            return is_int($cores) && $cores >= 1 ? $cores : null;
        }

        $capability = $this->getThreadCapability();
        if ($capability === null || !$capability->isControllable()) {
            return null;
        }

        $key = $capability->getArgumentKey();
        if (!array_key_exists($key, $this->args)) {
            return null;
        }

        $value = $this->args[$key];
        return is_int($value) && $value >= 1 ? $value : null;
    }

    abstract public static function getDefaultExecutable(): string;

    public function getExecutable(): string
    {
        return $this->executable;
    }

    public function applyExecutablePrefix(string $prefix): void
    {
        $this->executablePrefix = $prefix;
    }

    public function applyCliExtraArguments(string $args): void
    {
        $this->cliExtraArguments = $args;
    }

    protected function getEffectiveExecutable(): string
    {
        if ($this->executablePrefix !== '') {
            return $this->executablePrefix . ' ' . $this->executable;
        }

        return $this->executable;
    }

    /**
     * Resolve executable path: try vendor/bin/{tool} first, fall back to tool name.
     */
    protected function resolveExecutable(): string
    {
        $default = static::getDefaultExecutable();
        if ($default === '') {
            return $default;
        }
        $vendorPath = 'vendor/bin/' . $default;
        if ($this->fileExistsCheck($vendorPath)) {
            return $vendorPath;
        }
        return $default;
    }

    protected function fileExistsCheck(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Subcommand inserted right after the executable (e.g. "analyse" for phpstan).
     */
    protected function getSubcommand(): string
    {
        return '';
    }

    /**
     * Build the full CLI command string from executable + ARGUMENT_MAP + args.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Iterates ARGUMENT_MAP types + appends optional parts
     */
    public function buildCommand(): string
    {
        $parts = [$this->getEffectiveExecutable()];

        $subcommand = $this->getSubcommand();
        if ($subcommand !== '') {
            $parts[] = $subcommand;
        }

        $pathsPart = '';

        foreach (static::ARGUMENT_MAP as $key => $spec) {
            if (!array_key_exists($key, $this->args) || $this->isEmpty($this->args[$key])) {
                continue;
            }
            $result = $this->buildArgumentPart($key, $this->args[$key], $spec);
            if ($result === null) {
                $pathsPart = is_array($this->args[$key]) ? implode(' ', $this->args[$key]) : $this->args[$key];
            } else {
                array_push($parts, ...$result);
            }
        }

        if (!empty($this->args['other-arguments'])) {
            $parts[] = $this->args['other-arguments'];
        }

        if ($this->cliExtraArguments !== '') {
            $parts[] = $this->cliExtraArguments;
        }

        if ($pathsPart !== '') {
            $parts[] = $pathsPart;
        }

        return implode(' ', $parts);
    }

    /**
     * Build the CLI fragment(s) for a single argument.
     *
     * @param string $key  Config key name
     * @param mixed  $value Argument value (already validated as non-empty)
     * @param array<string, string> $spec  ARGUMENT_MAP entry
     * @return string[]|null Parts to append, or null for 'paths' (deferred)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Switch covers all ARGUMENT_MAP types
     */
    protected function buildArgumentPart(string $key, $value, array $spec): ?array
    {
        $flag = $spec['flag'] ?? '';
        $separator = $spec['separator'] ?? '=';

        switch ($spec['type'] ?? 'value') {
            case 'value':
                return [$flag . $separator . $value];
            case 'boolean':
                return $value ? [$flag] : [];
            case 'paths':
                return null;
            case 'csv':
                $list = is_array($value) ? implode(',', $value) : $value;
                return [$flag . $separator . $list];
            case 'repeat':
                $parts = [];
                foreach ((array) $value as $item) {
                    $parts[] = $flag . ' ' . $item;
                }
                return $parts;
            case 'key_value':
                return ["--$key=$value"];
            default:
                return [$flag . $separator . $value];
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDisplayName(): string
    {
        return $this->name;
    }

    /** @return string[] */
    public function getConfiguredPaths(): array
    {
        return $this->args['paths'] ?? [];
    }

    public function isIgnoreErrorsOnExit(): bool
    {
        return $this->ignoreErrorsOnExit;
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function setExecutionContext(ExecutionContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Whether this job validates in-process (no shell). Inline jobs skip command
     * generation and process creation; the scheduler calls {@see runInline()}
     * and uses its JobResult directly (FEAT-16, PAT-001). Default false — only
     * CommitMsgJob overrides it in v3.5.
     */
    public function isInline(): bool
    {
        return false;
    }

    /**
     * Run the job in-process and return its JobResult, using the execution
     * context already attached via {@see setExecutionContext()}. Only inline
     * jobs override this; the base throws so a misconfigured scheduler fails
     * loudly rather than silently producing an empty result.
     */
    public function runInline(): JobResult
    {
        throw new LogicException(sprintf("Inline execution is not implemented for job type '%s'.", $this->type));
    }

    /**
     * Whether this job supports fast mode (path filtering with input files).
     * Explicit `accelerable` in config takes precedence; otherwise uses the
     * subclass' SUPPORTS_FAST constant. Used by FlowExecutor to attach an
     * inputFiles slice only on accelerable jobs (REQ-008/REQ-009 of
     * spec-design-files-flag.md).
     */
    public function isAccelerable(): bool
    {
        if (array_key_exists('accelerable', $this->args)) {
            return (bool) $this->args['accelerable'];
        }
        $constant = static::class . '::SUPPORTS_FAST';
        return defined($constant) && (bool) constant($constant);
    }

    /**
     * Declare threading capability for budget allocation.
     * Override in subclasses that support internal parallelism.
     */
    public function getThreadCapability(): ?ThreadCapability
    {
        return null;
    }

    /**
     * Explicit cores reservation declared via the job's 'cores' keyword.
     * When set, the allocator pins this amount in the budget and, if the
     * capability is controllable, applyThreadLimit() gets called with this
     * exact value so the tool's native flag matches.
     */
    public function getCoresOverride(): ?int
    {
        return $this->coresOverride;
    }

    /**
     * Memory the job declares as scheduler reservation in MB. Equals the
     * integer value when 'memory' is declared in short form; null when
     * declared in extended form (thresholds only) or absent.
     */
    public function getMemoryReserve(): ?int
    {
        return $this->memoryReserve;
    }

    /**
     * Per-job memory threshold (warn-above / fail-above) declared in
     * jobs.<name>.memory. Null when not declared. The MemoryEvaluator uses
     * this to compute MEMORY_THRESHOLD_WARNED / FAILED on the JobResult.
     */
    public function getMemoryThreshold(): ?MemoryThreshold
    {
        return $this->memoryThreshold;
    }

    public function getWarnAfter(): ?int
    {
        return $this->warnAfter;
    }

    public function getFailAfter(): ?int
    {
        return $this->failAfter;
    }

    /**
     * Override the configured per-job thresholds (used by `githooks job` CLI flags).
     * `null` clears the threshold; positive integer sets it.
     */
    public function applyThresholdOverride(?int $warnAfter, ?int $failAfter): void
    {
        $this->warnAfter = $warnAfter;
        $this->failAfter = $failAfter;
    }

    /**
     * Apply thread limit from budget allocator.
     * Override in subclasses that support internal parallelism.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function applyThreadLimit(int $threads): void
    {
        // no-op by default
    }

    /** @SuppressWarnings(PHPMD.UnusedFormalParameter) */
    public function isFixApplied(int $exitCode): bool
    {
        return false;
    }

    /**
     * Whether a non-zero exit code reflects the tool refusing to operate
     * on an empty input set (everything the wrapper passed got dropped by
     * the tool's internal exclusions). Subclasses override to recognise
     * the tool-specific exit code + output signature.
     *
     * When this returns true, the executor reinterprets the JobResult as
     * skipped rather than failed: the tool didn't fail — it had nothing to do.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Default implementation
     *   ignores both arguments; subclasses inspect them.
     */
    public function isEmptyInputTolerated(int $exitCode, string $output): bool
    {
        return false;
    }

    /**
     * Whether this tool type supports structured output parsing (JSON).
     */
    public function supportsStructuredOutput(): bool
    {
        return false;
    }

    /**
     * Apply format overrides for structured output mode (codeclimate/sarif).
     * Subclasses override to inject JSON output flags (e.g. --error-format=json).
     * Returns true if overrides were applied.
     */
    public function applyStructuredOutputFormat(): bool
    {
        return false;
    }

    /**
     * Return paths to cache files/directories used by this tool.
     * Override in subclasses that produce caches.
     *
     * @return string[]
     */
    public function getCachePaths(): array
    {
        return [];
    }

    /**
     * Optional human-readable warning explaining why getCachePaths() may be
     * returning a fallback default rather than the real cache path. Used by
     * cache:clear to surface "I tried to parse your config but couldn't, so
     * I'm guessing the default" — only relevant for tools whose cache config
     * lives in PHP code (rector.php, .php-cs-fixer.php).
     *
     * Returning null (the default) means resolution succeeded or the job
     * does not have a cache.
     */
    public function getCacheResolutionWarning(): ?string
    {
        return null;
    }

    /** @param mixed $value */
    private function isEmpty($value): bool
    {
        if (is_bool($value)) {
            return false; // booleans are never "empty" for our purposes
        }
        return empty($value);
    }
}
