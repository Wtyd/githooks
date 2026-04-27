<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolver;
use Wtyd\GitHooks\Execution\ExecutionMode;

class EffectiveOptionsResolverTest extends TestCase
{
    private EffectiveOptionsResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EffectiveOptionsResolver();
    }

    /**
     * Build a ConfigurationResult with a single flow named $flowName and the given globals/flow opts.
     *
     * @param array<string, mixed> $globalOptionsRaw
     * @param array<string, mixed>|null $flowOptionsRaw
     */
    private function buildConfig(
        array $globalOptionsRaw,
        ?array $flowOptionsRaw,
        string $flowName = 'qa',
        ?string $flowExecution = null
    ): array {
        $validation = new ValidationResult();
        $globalOptions = $globalOptionsRaw === []
            ? new OptionsConfiguration()
            : OptionsConfiguration::fromArray($globalOptionsRaw, $validation);

        $flowOptions = $flowOptionsRaw === null
            ? null
            : OptionsConfiguration::fromArray($flowOptionsRaw, $validation);

        $flow = new FlowConfiguration($flowName, ['job_a'], $flowOptions, $flowExecution);

        $config = new ConfigurationResult(
            'githooks.php',
            $globalOptions,
            [],
            [$flowName => $flow],
            null,
            $validation
        );

        return [$config, $flow];
    }

    // ========================================================================
    // resolveSingle — single-flow degenerate / declarative meta-flow
    // ========================================================================

    /** @test */
    public function single_cli_overrides_flow_and_global()
    {
        [$config, $flow] = $this->buildConfig(
            ['processes' => 8, 'fail-fast' => false],
            ['processes' => 2, 'fail-fast' => true]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, false, 4, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(4, $trace['processes']['value']);
        $this->assertEquals('cli', $trace['processes']['source']);
        $this->assertFalse($trace['failFast']['value']);
        $this->assertEquals('cli', $trace['failFast']['source']);
        $this->assertEquals(4, $resolution->getOptions()->getProcesses());
        $this->assertFalse($resolution->getOptions()->isFailFast());
    }

    /** @test */
    public function single_cascades_to_flow_options_when_no_cli()
    {
        [$config, $flow] = $this->buildConfig(
            ['processes' => 8],
            ['processes' => 2, 'fail-fast' => true]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(2, $trace['processes']['value']);
        $this->assertEquals('flows.qa.options', $trace['processes']['source']);
        $this->assertTrue($trace['failFast']['value']);
        $this->assertEquals('flows.qa.options', $trace['failFast']['source']);
    }

    /** @test */
    public function single_cascades_per_key_falling_through_to_globals()
    {
        // flow declares only fail-fast; processes must fall through to globals
        [$config, $flow] = $this->buildConfig(
            ['processes' => 8],
            ['fail-fast' => true]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(8, $trace['processes']['value']);
        $this->assertEquals('flows.options', $trace['processes']['source']);
        $this->assertTrue($trace['failFast']['value']);
        $this->assertEquals('flows.qa.options', $trace['failFast']['source']);
    }

    /** @test */
    public function single_falls_to_default_when_neither_flow_nor_globals_declare_key()
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(1, $trace['processes']['value']);
        $this->assertEquals('default', $trace['processes']['source']);
        $this->assertFalse($trace['failFast']['value']);
        $this->assertEquals('default', $trace['failFast']['source']);
        $this->assertEquals(ExecutionMode::FULL, $trace['executionMode']['value']);
        $this->assertEquals('default', $trace['executionMode']['source']);
    }

    /** @test */
    public function single_picks_execution_mode_from_flow_when_no_cli_invocation()
    {
        [$config, $flow] = $this->buildConfig([], null, 'qa', ExecutionMode::FAST);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertEquals(ExecutionMode::FAST, $resolution->getExecutionMode());
        $trace = $resolution->getTrace();
        $this->assertEquals('flows.qa.options', $trace['executionMode']['source']);
    }

    /** @test */
    public function single_cli_invocation_mode_overrides_flow_execution()
    {
        [$config, $flow] = $this->buildConfig([], null, 'qa', ExecutionMode::FAST);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, ExecutionMode::FAST_BRANCH);

        $this->assertEquals(ExecutionMode::FAST_BRANCH, $resolution->getExecutionMode());
        $trace = $resolution->getTrace();
        $this->assertEquals('cli', $trace['executionMode']['source']);
    }

    /** @test */
    public function main_branch_appears_in_trace_when_fast_branch_mode()
    {
        [$config, $flow] = $this->buildConfig(['main-branch' => 'develop'], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, ExecutionMode::FAST_BRANCH);

        $trace = $resolution->getTrace();
        $this->assertArrayHasKey('mainBranch', $trace);
        $this->assertEquals('develop', $trace['mainBranch']['value']);
        $this->assertEquals('flows.options', $trace['mainBranch']['source']);
    }

    /** @test */
    public function main_branch_omitted_from_trace_in_full_mode_when_undeclared()
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertArrayNotHasKey('mainBranch', $resolution->getTrace());
    }

    // ========================================================================
    // resolveMultiple — ad-hoc and mixed modes
    // ========================================================================

    /** @test */
    public function multiple_ignores_flow_options_and_takes_globals()
    {
        // No flow argument; the flow exists but its options are deliberately ignored
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray(['processes' => 6, 'fail-fast' => true], $validation);
        $flowOptions = OptionsConfiguration::fromArray(['processes' => 1, 'fail-fast' => false], $validation);
        $flow = new FlowConfiguration('qa', ['job_a'], $flowOptions);

        $config = new ConfigurationResult('githooks.php', $globals, [], ['qa' => $flow], null, $validation);

        $resolution = $this->resolver->resolveMultiple($config, null, null, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(6, $trace['processes']['value']);
        $this->assertEquals('flows.options', $trace['processes']['source']);
        $this->assertTrue($trace['failFast']['value']);
        $this->assertEquals('flows.options', $trace['failFast']['source']);
    }

    /** @test */
    public function multiple_cli_overrides_globals()
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray(['processes' => 6, 'fail-fast' => true], $validation);
        $config = new ConfigurationResult('githooks.php', $globals, [], [], null, $validation);

        $resolution = $this->resolver->resolveMultiple($config, false, 12, null);

        $trace = $resolution->getTrace();
        $this->assertEquals(12, $trace['processes']['value']);
        $this->assertEquals('cli', $trace['processes']['source']);
        $this->assertEquals('cli', $trace['failFast']['source']);
    }

    /** @test */
    public function multiple_falls_to_default_with_no_cli_and_no_globals()
    {
        $validation = new ValidationResult();
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [],
            null,
            $validation
        );

        $resolution = $this->resolver->resolveMultiple($config, null, null, null);

        $trace = $resolution->getTrace();
        $this->assertEquals('default', $trace['processes']['source']);
        $this->assertEquals('default', $trace['failFast']['source']);
        $this->assertEquals('default', $trace['executionMode']['source']);
    }

    /** @test */
    public function multiple_never_uses_flow_execution_for_execution_mode()
    {
        $validation = new ValidationResult();
        $globals = new OptionsConfiguration();
        $flow = new FlowConfiguration('qa', ['job_a'], null, ExecutionMode::FAST);
        $config = new ConfigurationResult('githooks.php', $globals, [], ['qa' => $flow], null, $validation);

        // resolveMultiple does not receive the flow; the per-flow execution must not leak in
        $resolution = $this->resolver->resolveMultiple($config, null, null, null);

        $this->assertEquals(ExecutionMode::FULL, $resolution->getExecutionMode());
        $this->assertEquals('default', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function single_returns_options_configuration_with_resolved_values()
    {
        [$config, $flow] = $this->buildConfig(
            ['main-branch' => 'main'],
            ['processes' => 3, 'fail-fast' => true]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, 7, null);

        $options = $resolution->getOptions();
        $this->assertEquals(7, $options->getProcesses());
        $this->assertTrue($options->isFailFast());
        $this->assertEquals('main', $options->getMainBranch());
    }

    // ========================================================================
    // time-budget cascade (v3.3 item 4)
    // ========================================================================

    /** @test */
    public function single_time_budget_falls_to_default_when_unset(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertNull($resolution->getOptions()->getTimeBudget());
        $this->assertNull($resolution->getTrace()['timeBudget']['value']);
        $this->assertSame('default', $resolution->getTrace()['timeBudget']['source']);
    }

    /** @test */
    public function single_time_budget_picks_globals_when_only_globals_declared(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame(120, $resolution->getOptions()->getTimeBudget()->getWarnAfter());
        $this->assertSame('flows.options', $resolution->getTrace()['timeBudget']['source']);
    }

    /** @test */
    public function single_time_budget_flow_options_override_globals(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            ['time-budget' => ['warn-after' => 5, 'fail-after' => 15]]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame(5, $resolution->getOptions()->getTimeBudget()->getWarnAfter());
        $this->assertSame(15, $resolution->getOptions()->getTimeBudget()->getFailAfter());
        $this->assertSame('flows.qa.options', $resolution->getTrace()['timeBudget']['source']);
    }

    /** @test */
    public function single_cli_warn_and_fail_after_override_config(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null, 60, 600);

        $budget = $resolution->getOptions()->getTimeBudget();
        $this->assertSame(60, $budget->getWarnAfter());
        $this->assertSame(600, $budget->getFailAfter());
        $this->assertSame('cli', $resolution->getTrace()['timeBudget']['source']);
    }

    /** @test */
    public function single_cli_warn_after_alone_keeps_config_fail_after(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null, 60);

        $budget = $resolution->getOptions()->getTimeBudget();
        $this->assertSame(60, $budget->getWarnAfter());
        $this->assertSame(300, $budget->getFailAfter());
    }

    /** @test */
    public function single_no_time_budget_disables_everything(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null, null, null, true);

        $this->assertNull($resolution->getOptions()->getTimeBudget());
        $this->assertSame('cli', $resolution->getTrace()['timeBudget']['source']);
        $this->assertNull($resolution->getTrace()['timeBudget']['value']);
    }

    /** @test */
    public function multiple_uses_flows_options_time_budget_only(): void
    {
        [$config] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 120, 'fail-after' => 300]],
            ['time-budget' => ['warn-after' => 5, 'fail-after' => 15]]
        );

        $resolution = $this->resolver->resolveMultiple($config, null, null, null);

        // resolveMultiple ignores per-flow options (CON-001/002) — globals win.
        $this->assertSame(120, $resolution->getOptions()->getTimeBudget()->getWarnAfter());
        $this->assertSame('flows.options', $resolution->getTrace()['timeBudget']['source']);
    }
}
