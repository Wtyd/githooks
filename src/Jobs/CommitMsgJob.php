<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs;

use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Execution\ExecutionContext;
use Wtyd\GitHooks\Execution\JobResult;
use Wtyd\GitHooks\Jobs\CommitMessage\CommitMessagePresets;
use Wtyd\GitHooks\Jobs\CommitMessage\CommitMessageValidator;
use Wtyd\GitHooks\Jobs\CommitMessage\MessageFileResolver;
use Wtyd\GitHooks\Jobs\CommitMessage\ValidationOutcome;
use Wtyd\GitHooks\Utils\IsoTimestamp;

/**
 * Native `commit-msg` job (FEAT-16): validates the commit-message subject
 * against a declarative rule set, fully in-process (no shell). Wires to the git
 * `commit-msg` hook and replaces the bash-script `custom` job that consumers had
 * to write before — multiplatform, validated by `conf:check`, and producing the
 * same JobResult contract as any other job.
 *
 * Effective rules are resolved once at construction: an optional `preset`
 * expands to a rule bundle and explicit `rules` override it key by key
 * (REQ-012). The message file is located by {@see MessageFileResolver} and the
 * subject validated by {@see CommitMessageValidator}.
 */
class CommitMsgJob extends JobAbstract
{
    public const SUPPORTS_FAST = false;

    private const SUBJECT_SNIPPET_MAX = 100;

    private ?string $preset;

    /** @var array<string, mixed> */
    private array $resolvedRules;

    public function __construct(JobConfiguration $config)
    {
        parent::__construct($config);
        $raw = $config->getConfig();
        $this->preset = isset($raw['preset']) ? (string) $raw['preset'] : null;
        $explicitRules = (isset($raw['rules']) && is_array($raw['rules'])) ? $raw['rules'] : [];
        $this->resolvedRules = CommitMessagePresets::resolve($this->preset, $explicitRules);
    }

    public static function getDefaultExecutable(): string
    {
        return '';
    }

    public function isInline(): bool
    {
        return true;
    }

    public function runInline(): JobResult
    {
        $start = microtime(true);
        $context = $this->context ?? ExecutionContext::default();
        $resolver = $this->resolver();

        $path = $resolver->resolve($context->getCommitMessageFile(), null, null, $context->getCwd());
        if ($path === null) {
            return $this->failResult(
                "commit-msg: no message file available. Provide --message-file, --message, "
                . "or run via the 'commit-msg' git hook.",
                $start
            );
        }

        $raw = $resolver->readRaw($path);
        if ($raw === null) {
            return $this->failResult(sprintf("commit-msg: cannot read message file '%s'.", $path), $start);
        }

        $subject = CommitMessageValidator::extractSubject($raw);
        $outcome = $this->validator()->validate($subject, $this->resolvedRules);

        return $this->outcomeResult($outcome, $subject, $start);
    }

    protected function resolver(): MessageFileResolver
    {
        return new MessageFileResolver();
    }

    protected function validator(): CommitMessageValidator
    {
        return new CommitMessageValidator();
    }

    private function outcomeResult(ValidationOutcome $outcome, string $subject, float $start): JobResult
    {
        if ($outcome->isMerge()) {
            return $this->buildResult(true, '', $start, true, 'merge or fixup commit');
        }

        if ($outcome->isPassed()) {
            return $this->buildResult(true, '', $start, false, null);
        }

        return $this->buildResult(false, $this->humanFailure($outcome, $subject), $start, false, null);
    }

    private function humanFailure(ValidationOutcome $outcome, string $subject): string
    {
        $snippet = mb_substr($subject, 0, self::SUBJECT_SNIPPET_MAX);
        $lines = [
            sprintf("✗ commit-msg: subject failed rule '%s'.", $outcome->getFailedRule()),
            sprintf('  Subject:   %s', $snippet),
            sprintf('  Reason:    %s', $outcome->getReason()),
        ];
        if ($outcome->getExample() !== null) {
            $lines[] = sprintf('  Example:   %s', $outcome->getExample());
        }
        return implode("\n", $lines);
    }

    private function failResult(string $message, float $start): JobResult
    {
        return $this->buildResult(false, $message, $start, false, null);
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Internal builder; success/skip flags
     *   come from the validation outcome, not from a caller toggling behaviour.
     */
    private function buildResult(
        bool $success,
        string $output,
        float $start,
        bool $skipped,
        ?string $skipReason
    ): JobResult {
        $end = microtime(true);
        $elapsed = $end - $start;

        return new JobResult(
            $this->name,
            $success,
            $output,
            $this->formatTime($elapsed),
            false,
            '(inline commit-msg validation)',
            $this->type,
            $success ? 0 : 1,
            [],
            $skipped,
            $skipReason,
            $output,
            null,
            $elapsed,
            JobResult::THRESHOLD_NONE,
            null,
            null,
            null,
            IsoTimestamp::fromMicrotime($start),
            IsoTimestamp::fromMicrotime($end)
        );
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1.0) {
            return sprintf('%dms', (int) round($seconds * 1000));
        }
        return sprintf('%.2fs', $seconds);
    }
}
