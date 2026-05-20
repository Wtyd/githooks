<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssertWarningsTrait;
use Wtyd\GitHooks\Configuration\FlowOnRule;
use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * FEAT-2 · `on => [branch_pattern => attrs]` per flow.
 *
 * Covers Group A of the factor table: per-pattern attribute parsing. Whole-map
 * shape errors (Group A's A4, A5, A10) live in FlowConfigurationTest because
 * they belong to the surrounding section, not to a single rule.
 */
class FlowOnRuleTest extends TestCase
{
    use AssertWarningsTrait;

    // ========================================================================
    // fromArray — happy paths
    // ========================================================================

    /** @test */
    public function fromArray_with_execution_full_captures_rule()
    {
        // A2 (per-pattern slice)
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('master', ['execution' => 'full'], $result, 'ci-validation');

        $this->assertNotNull($rule);
        $this->assertSame('master', $rule->getPattern());
        $this->assertSame('full', $rule->getExecutionMode());
        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function fromArray_with_execution_fast_branch_captures_rule()
    {
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('*', ['execution' => 'fast-branch'], $result, 'ci-validation');

        $this->assertNotNull($rule);
        $this->assertSame('*', $rule->getPattern());
        $this->assertSame('fast-branch', $rule->getExecutionMode());
    }

    /** @test */
    public function fromArray_with_glob_pattern_captures_rule()
    {
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('release/v*', ['execution' => 'full'], $result, 'ci-validation');

        $this->assertNotNull($rule);
        $this->assertSame('release/v*', $rule->getPattern());
        $this->assertSame('full', $rule->getExecutionMode());
    }

    /** @test */
    public function fromArray_without_execution_returns_rule_without_mode()
    {
        // A rule with no recognised attributes — degenerate but accepted; FEAT-2
        // only supports 'execution', so the rule just doesn't override anything.
        // Useful when future attributes land.
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('master', [], $result, 'ci-validation');

        $this->assertNotNull($rule);
        $this->assertNull($rule->getExecutionMode());
        $this->assertFalse($result->hasErrors());
    }

    // ========================================================================
    // fromArray — validation errors
    // ========================================================================

    /** @test */
    public function fromArray_with_non_array_attrs_returns_error()
    {
        // A6 (per-pattern)
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('master', 'full', $result, 'ci-validation');

        $this->assertNull($rule);
        $this->assertErrorEquals(
            "Flow 'ci-validation' on rule for 'master': attributes must be an object.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_invalid_execution_mode_returns_error()
    {
        // A7
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('master', ['execution' => 'turbo'], $result, 'ci-validation');

        $this->assertNull($rule);
        $this->assertErrorEquals(
            "Flow 'ci-validation' on rule for 'master': 'execution' must be one of: full, fast, fast-branch.",
            $result
        );
    }

    /** @test */
    public function fromArray_with_non_string_execution_returns_error()
    {
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('master', ['execution' => 42], $result, 'ci-validation');

        $this->assertNull($rule);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function fromArray_with_empty_pattern_returns_error()
    {
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray('', ['execution' => 'full'], $result, 'ci-validation');

        $this->assertNull($rule);
        $this->assertErrorEquals(
            "Flow 'ci-validation' on rule: branch pattern must not be empty.",
            $result
        );
    }

    // ========================================================================
    // fromArray — unknown attribute warnings (did-you-mean)
    // ========================================================================

    /** @test */
    public function fromArray_with_typo_attribute_adds_warning_with_suggestion()
    {
        // A8
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray(
            'master',
            ['execution' => 'full', 'executon' => 'fast'],
            $result,
            'ci-validation'
        );

        $this->assertNotNull($rule);
        $this->assertFalse($result->hasErrors());
        $this->assertWarningEquals(
            "Flow 'ci-validation' on rule for 'master': unknown attribute 'executon'. Did you mean 'execution'?",
            $result
        );
    }

    /** @test */
    public function fromArray_with_unknown_attribute_warns_without_suggestion_when_far()
    {
        $result = new ValidationResult();
        $rule = FlowOnRule::fromArray(
            'master',
            ['execution' => 'full', 'completely-unrelated' => true],
            $result,
            'ci-validation'
        );

        $this->assertNotNull($rule);
        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('completely-unrelated', $result->getWarnings()[0]);
    }
}
