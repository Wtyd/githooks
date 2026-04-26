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
            "Flow 'lint': 'execution' must be one of: full, fast, fast-branch.",
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
