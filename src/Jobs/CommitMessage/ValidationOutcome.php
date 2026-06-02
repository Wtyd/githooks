<?php

declare(strict_types=1);

namespace Wtyd\GitHooks\Jobs\CommitMessage;

/**
 * Immutable result of validating a commit message subject (FEAT-16).
 *
 * Three shapes, produced by the named constructors:
 *   - pass()      — the subject satisfied every active rule.
 *   - mergeSkip()  — the subject is a merge/squash/fixup commit and
 *                    `merge-allowed` is on, so validation was skipped.
 *   - fail()      — the first failing rule, its human reason and an optional
 *                    one-line example of a valid subject.
 *
 * `subjectLength` is the UTF-8 code-point count (same metric as
 * min-length/max-length), surfaced in the JSON `commitMsg` block.
 */
final class ValidationOutcome
{
    private bool $passed;

    private bool $merge;

    private ?string $failedRule;

    private ?string $reason;

    private ?string $example;

    private int $subjectLength;

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Value object built only via the named constructors below.
     */
    private function __construct(
        bool $passed,
        bool $merge,
        ?string $failedRule,
        ?string $reason,
        ?string $example,
        int $subjectLength
    ) {
        $this->passed = $passed;
        $this->merge = $merge;
        $this->failedRule = $failedRule;
        $this->reason = $reason;
        $this->example = $example;
        $this->subjectLength = $subjectLength;
    }

    public static function pass(int $subjectLength): self
    {
        return new self(true, false, null, null, null, $subjectLength);
    }

    public static function mergeSkip(int $subjectLength): self
    {
        return new self(true, true, null, null, null, $subjectLength);
    }

    public static function fail(string $failedRule, string $reason, ?string $example, int $subjectLength): self
    {
        return new self(false, false, $failedRule, $reason, $example, $subjectLength);
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isMerge(): bool
    {
        return $this->merge;
    }

    public function getFailedRule(): ?string
    {
        return $this->failedRule;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getExample(): ?string
    {
        return $this->example;
    }

    public function getSubjectLength(): int
    {
        return $this->subjectLength;
    }
}
