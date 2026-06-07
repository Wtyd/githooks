<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Jobs\JobRegistry;
use Wtyd\GitHooks\Registry\ToolRegistry;

/**
 * Factor table C (FEAT-16): config-time validation of `commit-msg` jobs —
 * rejected keys (REQ-009), preset (REQ-011), rules and per-rule values
 * (REQ-010..013). Errors fail `conf:check` (exit 1); warnings do not.
 */
class JobConfigurationCommitMsgTest extends UnitTestCase
{
    private ToolRegistry $toolRegistry;

    private JobRegistry $jobRegistry;

    protected function setUp(): void
    {
        $this->toolRegistry = new ToolRegistry();
        $this->jobRegistry = new JobRegistry();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validate(array $config): ValidationResult
    {
        $result = new ValidationResult();
        JobConfiguration::fromArray('commit-format', $config, $this->toolRegistry, $result, $this->jobRegistry);
        return $result;
    }

    /** @test */
    public function preset_only_is_valid(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'preset' => 'conventional-commits']);

        $this->assertFalse($result->hasErrors(), implode(' | ', $result->getErrors()));
    }

    /** @test */
    public function rules_only_is_valid(): void
    {
        $result = $this->validate([
            'type'  => 'commit-msg',
            'rules' => [
                'pattern'         => '/^(feat|fix) \([\w-]+\) [A-Z]+-\d+ .+/',
                'pattern-message' => 'tipo (equipo) ID título',
                'min-length'      => 10,
                'merge-allowed'   => true,
            ],
        ]);

        $this->assertFalse($result->hasErrors(), implode(' | ', $result->getErrors()));
    }

    /** @test */
    public function preset_and_rules_override_is_valid(): void
    {
        $result = $this->validate([
            'type'   => 'commit-msg',
            'preset' => 'conventional-commits',
            'rules'  => ['max-length' => 120, 'subject-case' => null],
        ]);

        $this->assertFalse($result->hasErrors(), implode(' | ', $result->getErrors()));
    }

    /** @test */
    public function warn_after_and_fail_after_are_allowed(): void
    {
        $result = $this->validate([
            'type'        => 'commit-msg',
            'preset'      => 'conventional-commits',
            'warn-after'  => 1,
            'fail-after'  => 2,
        ]);

        $this->assertFalse($result->hasErrors(), implode(' | ', $result->getErrors()));
    }

    /**
     * @test
     * @dataProvider rejectedKeys
     *
     * @param mixed $value
     */
    public function rejected_key_is_not_applicable(string $key, $value): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'preset' => 'conventional-commits', $key => $value]);

        $this->assertTrue($result->hasErrors());
        $this->assertContains(
            "Job 'commit-format': key '$key' is not applicable to type 'commit-msg'.",
            $result->getErrors()
        );
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function rejectedKeys(): array
    {
        return [
            'paths'             => ['paths', ['src']],
            'executable-path'   => ['executable-path', 'vendor/bin/x'],
            'other-arguments'   => ['other-arguments', '--foo'],
            'accelerable'       => ['accelerable', true],
            'execution'         => ['execution', 'full'],
            'executable-prefix' => ['executable-prefix', 'php'],
            'cores'             => ['cores', 4],
            'memory'            => ['memory', 2000],
            'time-budget'       => ['time-budget', ['warn-after' => 5]],
        ];
    }

    /** @test */
    public function unknown_preset_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'preset' => 'gitmoji']);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): unknown preset 'gitmoji'. Available: 'conventional-commits'.",
            $result->getErrors()
        );
    }

    /** @test */
    public function invalid_regex_pattern_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['pattern' => '/[invalid']]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): rule 'pattern' is not a valid regular expression.",
            $result->getErrors()
        );
    }

    /** @test */
    public function min_not_less_than_max_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['min-length' => 50, 'max-length' => 20]]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): 'min-length' (50) must be less than 'max-length' (20).",
            $result->getErrors()
        );
    }

    /** @test */
    public function non_positive_length_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['min-length' => 0]]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): rule 'min-length' must be a positive integer.",
            $result->getErrors()
        );
    }

    /** @test Boundary: length of exactly 1 is valid (kills the `< 1` → `<= 1` mutant). */
    public function length_of_one_is_valid(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['min-length' => 1]]);

        $this->assertFalse($result->hasErrors(), implode(' | ', $result->getErrors()));
    }

    /** @test Boundary: min == max must error (kills the `>=` → `>` mutant). */
    public function equal_min_and_max_length_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['min-length' => 20, 'max-length' => 20]]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): 'min-length' (20) must be less than 'max-length' (20).",
            $result->getErrors()
        );
    }

    /** @test */
    public function invalid_subject_case_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['subject-case' => 'uppercase']]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): rule 'subject-case' must be one of 'lowercase', 'sentence' or null.",
            $result->getErrors()
        );
    }

    /** @test */
    public function non_boolean_flag_rule_is_an_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['merge-allowed' => 'yes']]);

        $this->assertContains(
            "Job 'commit-format' (commit-msg): rule 'merge-allowed' must be a boolean.",
            $result->getErrors()
        );
    }

    /** @test */
    public function unknown_rule_is_a_warning_not_error(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['body-length' => 72]]);

        $this->assertFalse($result->hasErrors());
        $this->assertContains("Job 'commit-format' (commit-msg): unknown rule 'body-length'.", $result->getWarnings());
    }

    /** @test */
    public function pattern_message_without_pattern_is_a_warning(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => ['pattern-message' => 'orphan']]);

        $this->assertFalse($result->hasErrors());
        $this->assertContains(
            "Job 'commit-format' (commit-msg): 'pattern-message' is ignored without 'pattern'.",
            $result->getWarnings()
        );
    }

    /** @test */
    public function rules_must_be_an_array(): void
    {
        $result = $this->validate(['type' => 'commit-msg', 'rules' => 'conventional']);

        $this->assertContains("Job 'commit-format' (commit-msg): 'rules' must be an array.", $result->getErrors());
    }
}
