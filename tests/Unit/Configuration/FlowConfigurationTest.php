<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssertWarningsTrait;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;

class FlowConfigurationTest extends TestCase
{
    use AssertWarningsTrait;

    /** @test */
    public function it_parses_a_valid_flow()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src', 'phpmd_src'],
        ], ['phpcs_src', 'phpmd_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($flow);
        $this->assertEquals('lint', $flow->getName());
        $this->assertEquals(['phpcs_src', 'phpmd_src'], $flow->getJobs());
        $this->assertNull($flow->getOptions());
    }

    /** @test */
    public function it_parses_flow_with_options()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'options' => ['fail-fast' => true],
            'jobs'    => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($flow->getOptions());
        $this->assertTrue($flow->getOptions()->isFailFast());
    }

    /**
     * @test
     * Exact-match error kills L49 Concat/ConcatOperandRemoval that would drop
     * either half of the two-sentence error message.
     */
    public function it_rejects_flow_named_as_git_hook()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('pre-commit', [
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals(
            "Flow 'pre-commit' cannot use a git hook event name. "
            . "Use the 'hooks' section to map events to flows.",
            $result
        );
    }

    /** @test */
    public function it_reports_error_when_jobs_is_empty()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', ['jobs' => []], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_reports_error_when_jobs_is_missing()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', ['options' => []], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_reports_warning_for_undefined_job_reference()
    {
        $result = new ValidationResult();
        FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src', 'nonexistent'],
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotEmpty($result->getWarnings());
        $this->assertStringContainsString('nonexistent', $result->getWarnings()[0]);
    }

    /**
     * @test
     *
     * Kills:
     *   - L241 Concat ×2 + ConcatOperandRemoval ×2 (undefined-job warning)
     *
     * Pin the literal message verbatim. The existing test only asserts the
     * job name appears somewhere in the warning, which the reordered or
     * partially-dropped concatenations could still satisfy.
     */
    public function undefined_job_reference_warning_message_is_assembled_with_exact_text(): void
    {
        $result = new ValidationResult();
        FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src', 'nonexistent'],
        ], ['phpcs_src'], $result);

        $expected = "Flow 'lint' references undefined job 'nonexistent'. It will be skipped.";
        $this->assertContains($expected, $result->getWarnings());
    }

    // ========================================================================
    // Execution mode (TDD — will fail until implementation exists)
    // ========================================================================

    /** @test */
    public function it_parses_flow_with_fast_execution()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs'      => ['phpcs_src'],
            'execution' => 'fast',
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast', $flow->getExecution());
    }

    /** @test */
    public function it_parses_flow_with_fast_branch_execution()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs'      => ['phpcs_src'],
            'execution' => 'fast-branch',
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('fast-branch', $flow->getExecution());
    }

    /** @test */
    public function it_parses_flow_with_full_execution()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs'      => ['phpcs_src'],
            'execution' => 'full',
        ], ['phpcs_src'], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals('full', $flow->getExecution());
    }

    /** @test */
    public function it_defaults_execution_to_null()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow->getExecution());
    }

    /**
     * @test
     * Kills L74 Concat/ConcatOperandRemoval on the execution-mode error and
     * L75 ReturnRemoval: with an invalid execution the factory must return null.
     */
    public function it_reports_error_and_returns_null_for_invalid_execution_mode()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs'      => ['phpcs_src'],
            'execution' => 'turbo',
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals(
            "Flow 'lint': 'execution' must be one of: full, fast, fast-branch, fast-dirty.",
            $result
        );
    }

    /** @test */
    public function execution_does_not_trigger_unknown_key_warning()
    {
        $result = new ValidationResult();
        FlowConfiguration::fromArray('lint', [
            'jobs'      => ['phpcs_src'],
            'execution' => 'full',
        ], ['phpcs_src'], $result);

        $this->assertEmpty($result->getWarnings());
    }

    // ========================================================================
    // FEAT-2 · `on` per flow — whole-map shape (per-pattern shape is in FlowOnRuleTest)
    // ========================================================================

    /** @test */
    public function on_absent_yields_null_getOn()
    {
        // A1
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNotNull($flow);
        $this->assertNull($flow->getOn());
    }

    /** @test */
    public function on_with_two_patterns_preserves_declaration_order()
    {
        // A2 + A3 + orden — D3: primer match gana
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-validation', [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast-branch'],
            ],
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNotNull($flow);
        $this->assertFalse($result->hasErrors());
        $rules = $flow->getOn();
        $this->assertNotNull($rules);
        $this->assertCount(2, $rules);
        $this->assertSame('master', $rules[0]->getPattern());
        $this->assertSame('full', $rules[0]->getExecutionMode());
        $this->assertSame('*', $rules[1]->getPattern());
        $this->assertSame('fast-branch', $rules[1]->getExecutionMode());
    }

    /** @test */
    public function on_as_non_array_returns_error()
    {
        // A4
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'on'   => 'master',
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals(
            "Flow 'qa': 'on' must be an array of branch patterns.",
            $result
        );
    }

    /** @test */
    public function on_as_empty_array_warns_about_no_effect()
    {
        // A5
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'on'   => [],
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNotNull($flow);
        $this->assertFalse($result->hasErrors());
        $this->assertWarningEquals(
            "Flow 'qa': 'on' is declared but empty; it will be ignored.",
            $result
        );
        $this->assertNull($flow->getOn());
    }

    /** @test */
    public function on_without_catch_all_warns_about_fallback()
    {
        // A10
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'on'   => ['master' => ['execution' => 'full']],
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNotNull($flow);
        $this->assertFalse($result->hasErrors());
        $this->assertWarningEquals(
            "Flow 'qa': 'on' has no catch-all '*' pattern; non-matching branches fall back to "
            . "flow.execution / flows.options.execution / default.",
            $result
        );
    }

    /** @test */
    public function on_with_catch_all_does_not_warn()
    {
        $result = new ValidationResult();
        FlowConfiguration::fromArray('qa', [
            'on' => [
                'master' => ['execution' => 'full'],
                '*'      => ['execution' => 'fast-branch'],
            ],
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertEmpty($result->getWarnings());
    }

    /** @test */
    public function on_coexists_with_flow_level_execution()
    {
        // A11 — both declared, both retained; resolver decides cascade
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'on'        => ['master' => ['execution' => 'full']],
            'execution' => 'fast',
            'jobs'      => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNotNull($flow);
        $this->assertSame('fast', $flow->getExecution());
        $this->assertNotNull($flow->getOn());
        $this->assertCount(1, $flow->getOn());
    }

    /** @test */
    public function on_propagates_per_pattern_error_and_returns_null()
    {
        // A6 percola: si una entrada falla, todo el flow falla
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('qa', [
            'on'   => ['master' => 'full'],   // attrs no array
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals(
            "Flow 'qa' on rule for 'master': attributes must be an object.",
            $result
        );
    }

    // ========================================================================
    // Meta-flows (v3.3 — xor jobs/flows)
    // ========================================================================

    /** @test */
    public function it_parses_a_meta_flow_with_flow_references()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-pack', [
            'flows' => ['qa', 'lint'],
        ], [], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertNotNull($flow);
        $this->assertTrue($flow->isMetaFlow());
        $this->assertEquals(['qa', 'lint'], $flow->getFlowReferences());
        $this->assertEquals([], $flow->getJobs());
    }

    /** @test */
    public function it_parses_meta_flow_with_options()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-pack', [
            'flows'   => ['qa', 'lint'],
            'options' => ['processes' => 4, 'fail-fast' => true],
        ], [], $result);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($flow->isMetaFlow());
        $this->assertNotNull($flow->getOptions());
        $this->assertEquals(4, $flow->getOptions()->getProcesses());
        $this->assertTrue($flow->getOptions()->isFailFast());
    }

    /** @test */
    public function normal_flow_reports_is_not_a_meta_flow()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('lint', [
            'jobs' => ['phpcs_src'],
        ], ['phpcs_src'], $result);

        $this->assertFalse($flow->isMetaFlow());
        $this->assertEquals([], $flow->getFlowReferences());
    }

    /** @test */
    public function it_rejects_flow_with_both_jobs_and_flows()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('mixed', [
            'jobs'  => ['phpcs_src'],
            'flows' => ['qa'],
        ], ['phpcs_src'], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals(
            "Flow 'mixed' declares both 'jobs' and 'flows'; pick one.",
            $result
        );
    }

    /** @test */
    public function it_rejects_flow_with_neither_jobs_nor_flows()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('orphan', [
            'options' => ['processes' => 4],
        ], [], $result);

        $this->assertNull($flow);
        $this->assertErrorEquals("Flow 'orphan' has neither 'jobs' nor 'flows'.", $result);
    }

    /** @test */
    public function it_rejects_meta_flow_when_flows_is_not_an_array()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-pack', [
            'flows' => 'qa,lint',
        ], [], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_rejects_meta_flow_when_flow_references_are_not_strings()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-pack', [
            'flows' => ['qa', 42],
        ], [], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_rejects_meta_flow_with_empty_string_reference()
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray('ci-pack', [
            'flows' => ['qa', ''],
        ], [], $result);

        $this->assertNull($flow);
        $this->assertTrue($result->hasErrors());
    }
}
