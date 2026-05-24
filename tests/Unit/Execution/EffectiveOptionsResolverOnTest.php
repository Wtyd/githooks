<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\FlowOnRule;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\EffectiveOptionsResolver;
use Wtyd\GitHooks\Execution\ExecutionMode;
use Wtyd\GitHooks\Utils\BranchResolution;

/**
 * FEAT-2 · Groups B + D — matching of branch-pattern rules and the resulting
 * cascade for execution mode.
 *
 *  Cascade:
 *    1. CLI (--fast / --fast-branch) →             source `cli`
 *    2. flows.<X>.on (matched against branch) →    source `flows.<X>.on`
 *    3. flows.<X>.execution →                      source `flows.<X>.options`
 *    4. flows.options.execution →                  source `flows.options`
 *    5. default →                                  source `default`
 */
class EffectiveOptionsResolverOnTest extends TestCase
{
    private EffectiveOptionsResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EffectiveOptionsResolver();
    }

    /** @test */
    public function B1_literal_master_match_yields_full()
    {
        $resolution = $this->resolveWithOn(
            'master',
            [
                ['master', 'full'],
                ['*', 'fast-branch'],
            ]
        );

        $this->assertSame(ExecutionMode::FULL, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.on', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function B2_catch_all_match_when_no_literal_matches()
    {
        $resolution = $this->resolveWithOn(
            'feature/x',
            [
                ['master', 'full'],
                ['*', 'fast-branch'],
            ]
        );

        $this->assertSame(ExecutionMode::FAST_BRANCH, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.on', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function B3_first_match_wins_glob_before_glob()
    {
        // `release/v*` declared first → it wins over `release/*` for `release/v1`
        $resolution = $this->resolveWithOn(
            'release/v1',
            [
                ['release/v*', 'full'],
                ['release/*', 'fast-branch'],
                ['*', 'fast'],
            ]
        );

        $this->assertSame(ExecutionMode::FULL, $resolution->getExecutionMode());
    }

    /** @test */
    public function B4_first_match_wins_falls_to_second_glob()
    {
        $resolution = $this->resolveWithOn(
            'release/legacy',
            [
                ['release/v*', 'full'],
                ['release/*', 'fast-branch'],
                ['*', 'fast'],
            ]
        );

        $this->assertSame(ExecutionMode::FAST_BRANCH, $resolution->getExecutionMode());
    }

    /** @test */
    public function B5_falls_through_to_catch_all()
    {
        $resolution = $this->resolveWithOn(
            'master',
            [
                ['release/v*', 'full'],
                ['release/*', 'fast-branch'],
                ['*', 'fast'],
            ]
        );

        $this->assertSame(ExecutionMode::FAST, $resolution->getExecutionMode());
    }

    /** @test */
    public function B6_no_match_no_catch_all_falls_to_flow_execution()
    {
        // `on` only has `master`; branch is `feature/x`; flow.execution = fast → wins
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow(
                'ci',
                'fast',
                [new FlowOnRule('master', 'full')]
            ),
            null,
            $this->branch('feature/x')
        );

        $this->assertSame(ExecutionMode::FAST, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.options', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function B7_first_match_wins_glob_before_literal_when_declared_in_that_order()
    {
        // D3 decision: declaration order matters; if 'ma*' is declared before
        // 'master', it shadows the literal.
        $resolution = $this->resolveWithOn(
            'master',
            [
                ['ma*', 'full'],
                ['master', 'fast-branch'],
            ]
        );

        $this->assertSame(ExecutionMode::FULL, $resolution->getExecutionMode());
    }

    /** @test */
    public function D1_cli_fast_wins_over_everything()
    {
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', 'fast-branch', [new FlowOnRule('master', 'full')]),
            ExecutionMode::FAST,
            $this->branch('master')
        );

        $this->assertSame(ExecutionMode::FAST, $resolution->getExecutionMode());
        $this->assertSame('cli', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function D2_cli_fast_branch_wins_even_when_on_matches_master_as_full()
    {
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', null, [new FlowOnRule('master', 'full')]),
            ExecutionMode::FAST_BRANCH,
            $this->branch('master')
        );

        $this->assertSame(ExecutionMode::FAST_BRANCH, $resolution->getExecutionMode());
        $this->assertSame('cli', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function D3_on_match_wins_when_no_cli()
    {
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', null, [new FlowOnRule('master', 'full')]),
            null,
            $this->branch('master')
        );

        $this->assertSame(ExecutionMode::FULL, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.on', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function D4_falls_to_flow_execution_when_on_does_not_match()
    {
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', 'fast', [new FlowOnRule('master', 'full')]),
            null,
            $this->branch('feature/x')
        );

        $this->assertSame(ExecutionMode::FAST, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.options', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function D7_catch_all_match_overrides_flow_execution()
    {
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', 'full', [new FlowOnRule('*', 'fast-branch')]),
            null,
            $this->branch('anything')
        );

        $this->assertSame(ExecutionMode::FAST_BRANCH, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.on', $resolution->getTrace()['executionMode']['source']);
    }

    /** @test */
    public function null_branch_resolution_skips_on_lookup()
    {
        // Defensive: when the resolver has no branch info (e.g. called from a
        // code path that doesn't know the branch), `on` is silently ignored
        // and the rest of the cascade applies.
        $resolution = $this->resolveSingleFlow(
            $this->makeFlow('ci', 'fast', [new FlowOnRule('master', 'full')]),
            null,
            null
        );

        $this->assertSame(ExecutionMode::FAST, $resolution->getExecutionMode());
        $this->assertSame('flows.ci.options', $resolution->getTrace()['executionMode']['source']);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rules tuples [pattern, executionMode]
     */
    private function resolveWithOn(string $branch, array $rules)
    {
        $ruleObjects = array_map(fn(array $t) => new FlowOnRule($t[0], $t[1]), $rules);
        return $this->resolveSingleFlow(
            $this->makeFlow('ci', null, $ruleObjects),
            null,
            $this->branch($branch)
        );
    }

    /**
     * @param FlowOnRule[] $on
     */
    private function makeFlow(string $name, ?string $execution, array $on): FlowConfiguration
    {
        return new FlowConfiguration(
            $name,
            ['phpcs_src'],
            null,
            $execution,
            null,
            null,
            $on
        );
    }

    private function branch(string $name): BranchResolution
    {
        return new BranchResolution($name, 'git');
    }

    private function resolveSingleFlow(
        FlowConfiguration $flow,
        ?string $invocationMode,
        ?BranchResolution $branchResolution
    ) {
        $config = new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(),
            [],
            [$flow->getName() => $flow],
            null,
            new ValidationResult()
        );

        return $this->resolver->resolveSingle(
            $config,
            $flow,
            null,
            null,
            $invocationMode,
            null,
            null,
            false,
            null,
            null,
            false,
            null,
            null,
            $branchResolution
        );
    }
}
