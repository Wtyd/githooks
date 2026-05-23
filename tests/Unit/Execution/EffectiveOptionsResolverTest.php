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

    /**
     * @test
     * Mata mutantes Identical y LogicalAnd en línea 348 (`mergeCliTimeBudget`).
     * Setup: solo CLI warn-after, sin fail-after, sin config. Real construye
     * un budget con warn=60/fail=null; mutados retornarían null.
     */
    public function single_cli_warn_after_only_with_no_config_builds_partial_budget(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null, 60);

        $budget = $resolution->getOptions()->getTimeBudget();
        $this->assertNotNull($budget);
        $this->assertSame(60, $budget->getWarnAfter());
        $this->assertNull($budget->getFailAfter());
        $this->assertSame('cli', $resolution->getTrace()['timeBudget']['source']);
    }

    /**
     * @test
     * Mata las mutaciones espejo en línea 348: solo CLI fail-after, sin warn,
     * sin config. Real construye warn=null/fail=600.
     */
    public function single_cli_fail_after_only_with_no_config_builds_partial_budget(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null, null, 600);

        $budget = $resolution->getOptions()->getTimeBudget();
        $this->assertNotNull($budget);
        $this->assertNull($budget->getWarnAfter());
        $this->assertSame(600, $budget->getFailAfter());
        $this->assertSame('cli', $resolution->getTrace()['timeBudget']['source']);
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

    // ========================================================================
    // memory-budget cascade (v3.3 — gh-48)
    // ========================================================================

    /** @test */
    public function single_memory_budget_cascades_from_flow_to_global(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 3500, 'fail-above' => 3900]],
            ['memory-budget' => ['warn-above' => 800]]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $budget = $resolution->getOptions()->getMemoryBudget();
        $this->assertNotNull($budget);
        $this->assertSame(800, $budget->getWarnAbove());
        $this->assertSame('flows.qa.options', $resolution->getTrace()['memoryBudget']['source']);
    }

    /** @test */
    public function single_memory_budget_falls_back_to_globals(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 3500]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame(3500, $resolution->getOptions()->getMemoryBudget()->getWarnAbove());
        $this->assertSame('flows.options', $resolution->getTrace()['memoryBudget']['source']);
    }

    /** @test */
    public function single_memory_budget_default_when_undeclared(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertNull($resolution->getOptions()->getMemoryBudget());
        $this->assertSame('default', $resolution->getTrace()['memoryBudget']['source']);
        $this->assertNull($resolution->getTrace()['memoryBudget']['value']);
    }

    /**
     * @test
     * Mata mutantes Identical y LogicalAnd en línea 435 (`mergeCliMemoryBudget`).
     * Setup: solo CLI warn-above, sin fail-above, sin config. Real construye
     * budget con warn=1500/fail=null; mutados retornarían null.
     */
    public function single_cli_memory_warn_above_only_with_no_config_builds_partial_budget(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            1500
        );

        $budget = $resolution->getOptions()->getMemoryBudget();
        $this->assertNotNull($budget);
        $this->assertSame(1500, $budget->getWarnAbove());
        $this->assertNull($budget->getFailAbove());
        $this->assertSame('cli', $resolution->getTrace()['memoryBudget']['source']);
    }

    /**
     * @test
     * Mata las mutaciones espejo en línea 435: solo CLI fail-above, sin warn,
     * sin config.
     */
    public function single_cli_memory_fail_above_only_with_no_config_builds_partial_budget(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            null,
            2500
        );

        $budget = $resolution->getOptions()->getMemoryBudget();
        $this->assertNotNull($budget);
        $this->assertNull($budget->getWarnAbove());
        $this->assertSame(2500, $budget->getFailAbove());
        $this->assertSame('cli', $resolution->getTrace()['memoryBudget']['source']);
    }

    /** @test */
    public function single_cli_memory_warn_above_overrides_config(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 3500, 'fail-above' => 3900]],
            null
        );

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            1500
        );

        $budget = $resolution->getOptions()->getMemoryBudget();
        $this->assertSame(1500, $budget->getWarnAbove());
        $this->assertSame(3900, $budget->getFailAbove(), 'CLI partial override preserves fail-above');
        $this->assertSame('cli', $resolution->getTrace()['memoryBudget']['source']);
    }

    /** @test */
    public function single_cli_no_memory_budget_disables_everything(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 3500, 'fail-above' => 3900]],
            null
        );

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            null,
            null,
            true
        );

        $this->assertNull($resolution->getOptions()->getMemoryBudget());
        $this->assertSame('cli', $resolution->getTrace()['memoryBudget']['source']);
        $this->assertNull($resolution->getTrace()['memoryBudget']['value']);
    }

    /** @test */
    public function multiple_memory_budget_uses_globals_only(): void
    {
        [$config] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 3500]],
            ['memory-budget' => ['warn-above' => 1000]]
        );

        $resolution = $this->resolver->resolveMultiple($config, null, null, null);

        $this->assertSame(3500, $resolution->getOptions()->getMemoryBudget()->getWarnAbove());
        $this->assertSame('flows.options', $resolution->getTrace()['memoryBudget']['source']);
    }

    // ========================================================================
    // allocator cascade
    // ========================================================================

    /** @test */
    public function single_allocator_default_when_undeclared(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame('fifo', $resolution->getOptions()->getAllocator());
        $this->assertSame('default', $resolution->getTrace()['allocator']['source']);
    }

    /** @test */
    public function single_allocator_cascades_from_flow_to_global(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['allocator' => 'fifo'],
            ['allocator' => 'greedy']
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame('greedy', $resolution->getOptions()->getAllocator());
        $this->assertSame('flows.qa.options', $resolution->getTrace()['allocator']['source']);
    }

    /** @test */
    public function single_cli_allocator_overrides_config(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['allocator' => 'fifo'],
            ['allocator' => 'fifo']
        );

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            null,
            null,
            false,
            'greedy'
        );

        $this->assertSame('greedy', $resolution->getOptions()->getAllocator());
        $this->assertSame('cli', $resolution->getTrace()['allocator']['source']);
    }

    // ========================================================================
    // stats cascade
    // ========================================================================

    /** @test */
    public function single_stats_default_when_undeclared(): void
    {
        [$config, $flow] = $this->buildConfig([], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertFalse($resolution->getOptions()->isStats());
        $this->assertFalse($resolution->getTrace()['stats']['value']);
    }

    /** @test */
    public function single_stats_cascades_from_flow_to_global(): void
    {
        [$config, $flow] = $this->buildConfig(
            ['stats' => false],
            ['stats' => true]
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertTrue($resolution->getOptions()->isStats());
        $this->assertSame('flows.qa.options', $resolution->getTrace()['stats']['source']);
    }

    /** @test */
    public function single_cli_stats_overrides_config(): void
    {
        [$config, $flow] = $this->buildConfig(['stats' => false], null);

        $resolution = $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            null,
            null,
            null,
            false,
            null,
            null,
            false,
            null,
            true
        );

        $this->assertTrue($resolution->getOptions()->isStats());
        $this->assertSame('cli', $resolution->getTrace()['stats']['source']);
    }

    // ========================================================================
    // Mutation testing reinforcements (cluster D)
    // ========================================================================

    /** @test */
    public function trace_time_budget_value_exposes_both_warn_after_and_fail_after_keys(): void
    {
        // Kills ArrayItem / ArrayItemRemoval mutants on traceTimeBudget()
        // at line 365: removing either entry would break consumers that
        // read $trace['timeBudget']['value']['warnAfter'].
        [$config, $flow] = $this->buildConfig(
            ['time-budget' => ['warn-after' => 90, 'fail-after' => 240]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);
        $value = $resolution->getTrace()['timeBudget']['value'];

        $this->assertIsArray($value);
        $this->assertArrayHasKey('warnAfter', $value);
        $this->assertArrayHasKey('failAfter', $value);
        $this->assertSame(90, $value['warnAfter']);
        $this->assertSame(240, $value['failAfter']);
    }

    /** @test */
    public function trace_memory_budget_value_exposes_both_warn_above_and_fail_above_keys(): void
    {
        // Kills ArrayItem / ArrayItemRemoval mutants on traceMemoryBudget()
        // at line 450 — same pattern as the time-budget trace.
        [$config, $flow] = $this->buildConfig(
            ['memory-budget' => ['warn-above' => 1500, 'fail-above' => 2500]],
            null
        );

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);
        $value = $resolution->getTrace()['memoryBudget']['value'];

        $this->assertIsArray($value);
        $this->assertArrayHasKey('warnAbove', $value);
        $this->assertArrayHasKey('failAbove', $value);
        $this->assertSame(1500, $value['warnAbove']);
        $this->assertSame(2500, $value['failAbove']);
    }

    /** @test */
    public function processes_trace_value_is_strict_int_not_coerced_string(): void
    {
        // Kills CastInt mutants on lines 512 and 517 in cascadeInt:
        // assert with strict assertSame() so a non-int (e.g. string '8'
        // or float 8.0) value would fail the equality check.
        [$config, $flow] = $this->buildConfig(['processes' => 8], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);
        $value = $resolution->getTrace()['processes']['value'];

        $this->assertSame(8, $value);
        $this->assertIsInt($value);
    }

    /** @test */
    public function fail_fast_trace_value_is_strict_bool_not_coerced(): void
    {
        // Kills CastBool mutant on line 489 in cascadeBool.
        [$config, $flow] = $this->buildConfig(['fail-fast' => true], null);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);
        $value = $resolution->getTrace()['failFast']['value'];

        $this->assertTrue($value);
        $this->assertIsBool($value);
    }

    // ========================================================================
    // BUG-20 — per-key cascade for executable-prefix, fast-branch-fallback and
    // reports. These three keys used to be read block-level via
    // `$base = $flowOptions ?? $globalOptions` and lost the global value when
    // the flow declared its own `options:` block to override an unrelated key
    // (e.g. processes or fail-fast).
    // ========================================================================

    /**
     * Decision table for a per-key cascade. Same factor space for each of the
     * three keys: (global declared?, flow declares options?, flow declares the
     * key?). The bug used to surface on row #2.
     *
     * @return array<string, array{0: array<string,mixed>, 1: ?array<string,mixed>, 2: string}>
     */
    public function executablePrefixCascadeProvider(): array
    {
        return [
            // [globalRaw, flowOptsRaw, expected]
            'row1 — global set, flow has no options'                   => [['executable-prefix' => 'docker exec app'], null, 'docker exec app'],
            'row2 — global set, flow declares options without prefix'  => [['executable-prefix' => 'docker exec app'], ['fail-fast' => true], 'docker exec app'],
            'row3 — global set, flow overrides prefix'                 => [['executable-prefix' => 'docker exec app'], ['executable-prefix' => 'php7.4'], 'php7.4'],
            'row4 — global empty, flow declares options without prefix' => [[], ['fail-fast' => true], ''],
            'row5 — global empty, flow declares prefix'                => [[], ['executable-prefix' => 'php7.4'], 'php7.4'],
            'row6 — neither side declares prefix'                      => [[], null, ''],
        ];
    }

    /**
     * @test
     * @dataProvider executablePrefixCascadeProvider
     * @param array<string, mixed> $globalRaw
     * @param array<string, mixed>|null $flowOptsRaw
     */
    public function single_cascades_executable_prefix_per_key(array $globalRaw, ?array $flowOptsRaw, string $expected): void
    {
        [$config, $flow] = $this->buildConfig($globalRaw, $flowOptsRaw);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame($expected, $resolution->getOptions()->getExecutablePrefix());
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: ?array<string,mixed>, 2: string}>
     */
    public function fastBranchFallbackCascadeProvider(): array
    {
        return [
            'row1 — global set, flow has no options'                    => [['fast-branch-fallback' => 'fast'], null, 'fast'],
            'row2 — global set, flow declares options without fallback' => [['fast-branch-fallback' => 'fast'], ['fail-fast' => true], 'fast'],
            'row3 — global set, flow overrides fallback'                => [['fast-branch-fallback' => 'fast'], ['fast-branch-fallback' => 'full'], 'full'],
            'row4 — global default, flow declares options without key'  => [[], ['fail-fast' => true], 'full'],
            'row5 — global default, flow overrides fallback'            => [[], ['fast-branch-fallback' => 'fast'], 'fast'],
            'row6 — neither side declares fallback'                     => [[], null, 'full'],
        ];
    }

    /**
     * @test
     * @dataProvider fastBranchFallbackCascadeProvider
     * @param array<string, mixed> $globalRaw
     * @param array<string, mixed>|null $flowOptsRaw
     */
    public function single_cascades_fast_branch_fallback_per_key(array $globalRaw, ?array $flowOptsRaw, string $expected): void
    {
        [$config, $flow] = $this->buildConfig($globalRaw, $flowOptsRaw);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame($expected, $resolution->getOptions()->getFastBranchFallback());
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: ?array<string,mixed>, 2: array<string,string>}>
     */
    public function reportsCascadeProvider(): array
    {
        $globalReports = ['junit' => 'qa.xml'];
        $flowReports = ['sarif' => 'qa.sarif'];
        return [
            'row1 — global set, flow has no options'                   => [['reports' => $globalReports], null, $globalReports],
            'row2 — global set, flow declares options without reports' => [['reports' => $globalReports], ['fail-fast' => true], $globalReports],
            'row3 — global set, flow overrides reports'                => [['reports' => $globalReports], ['reports' => $flowReports], $flowReports],
            'row4 — global empty, flow declares options without key'   => [[], ['fail-fast' => true], []],
            'row5 — global empty, flow overrides reports'              => [[], ['reports' => $flowReports], $flowReports],
            'row6 — neither side declares reports'                     => [[], null, []],
        ];
    }

    /**
     * @test
     * @dataProvider reportsCascadeProvider
     * @param array<string, mixed> $globalRaw
     * @param array<string, mixed>|null $flowOptsRaw
     * @param array<string, string> $expected
     */
    public function single_cascades_reports_per_key(array $globalRaw, ?array $flowOptsRaw, array $expected): void
    {
        [$config, $flow] = $this->buildConfig($globalRaw, $flowOptsRaw);

        $resolution = $this->resolver->resolveSingle($config, $flow, null, null, null);

        $this->assertSame($expected, $resolution->getOptions()->getReports());
    }

    /**
     * Meta-flow declarative path: same `resolveSingle` cascade, same expected
     * outcome for row #2. Guards the bug from coming back via the meta-flow
     * code path which is how it was first reported in production.
     *
     * @test
     */
    public function single_inherits_global_keys_for_meta_flow_with_partial_options(): void
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray(
            ['executable-prefix' => 'docker exec app', 'fast-branch-fallback' => 'fast', 'reports' => ['junit' => 'qa.xml']],
            $validation
        );
        $metaOpts = OptionsConfiguration::fromArray(['processes' => 4], $validation);
        $metaFlow = new FlowConfiguration('ci-validation', [], $metaOpts, null, ['qa']);

        $config = new ConfigurationResult('githooks.php', $globals, [], ['ci-validation' => $metaFlow], null, $validation);

        $resolution = $this->resolver->resolveSingle($config, $metaFlow, null, null, null);
        $options = $resolution->getOptions();

        $this->assertSame('docker exec app', $options->getExecutablePrefix());
        $this->assertSame('fast', $options->getFastBranchFallback());
        $this->assertSame(['junit' => 'qa.xml'], $options->getReports());
        // The flow's own override still wins:
        $this->assertSame(4, $options->getProcesses());
    }

    /**
     * Guardrail for `resolveMultiple` (CON-001/002): the fix must not change
     * the ad-hoc / mixed cascade, where per-flow options are ignored entirely
     * and the three keys come from globals only.
     *
     * @test
     */
    public function multiple_keeps_block_cascade_from_globals_for_prefix_fallback_reports(): void
    {
        $validation = new ValidationResult();
        $globals = OptionsConfiguration::fromArray(
            ['executable-prefix' => 'docker exec app', 'fast-branch-fallback' => 'fast', 'reports' => ['sarif' => 'qa.sarif']],
            $validation
        );
        // A flow exists with its own options but the multi-flow cascade must ignore them.
        $flowOpts = OptionsConfiguration::fromArray(
            ['executable-prefix' => 'IGNORED', 'fast-branch-fallback' => 'full', 'reports' => ['junit' => 'IGNORED.xml']],
            $validation
        );
        $flow = new FlowConfiguration('qa', ['job_a'], $flowOpts);
        $config = new ConfigurationResult('githooks.php', $globals, [], ['qa' => $flow], null, $validation);

        $resolution = $this->resolver->resolveMultiple($config, null, null, null);
        $options = $resolution->getOptions();

        $this->assertSame('docker exec app', $options->getExecutablePrefix());
        $this->assertSame('fast', $options->getFastBranchFallback());
        $this->assertSame(['sarif' => 'qa.sarif'], $options->getReports());
    }
}
