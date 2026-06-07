<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\MultiFlowExpansion;

/**
 * Unit coverage for the pure expansion / merge helpers (spec §4.3 +
 * FEAT-1/FEAT-3). These were previously exercised only indirectly through
 * FlowPreparer (integration) and the command (system); this file pins the
 * factor table at its lowest level:
 *
 *   F1 invocation mode  : single-normal · meta(declarative) · ad-hoc · mixed
 *   F2 entry attribute  : none · needs · only-files · exclude-files
 *   F3 dedup situation  : job once · job in ≥2 flows (first-occurrence-wins)
 *
 * Observables: merged ref order, preserved attributes per ref, reconstructed
 * `needs` graph.
 */
class MultiFlowExpansionTest extends UnitTestCase
{
    // ─── expandFlowNames (F1) ────────────────────────────────────────

    /** @test single normal flow expands to itself */
    public function expand_single_normal_flow_returns_itself(): void
    {
        $config = $this->config([
            'qa' => $this->flow('qa', ['a', 'b']),
        ]);

        $this->assertSame(['qa'], MultiFlowExpansion::expandFlowNames(['qa'], $config));
    }

    /** @test a meta-flow expands to its referenced flows */
    public function expand_meta_flow_returns_referenced_flows(): void
    {
        $config = $this->config([
            'qa'      => $this->flow('qa', ['a']),
            'lint'    => $this->flow('lint', ['b']),
            'ci-pack' => $this->metaFlow('ci-pack', ['qa', 'lint']),
        ]);

        $this->assertSame(['qa', 'lint'], MultiFlowExpansion::expandFlowNames(['ci-pack'], $config));
    }

    /** @test mixed mode dedups a normal flow already pulled in by a meta-flow */
    public function expand_mixed_dedups_overlapping_flow_names(): void
    {
        $config = $this->config([
            'qa'      => $this->flow('qa', ['a']),
            'lint'    => $this->flow('lint', ['b']),
            'ci-pack' => $this->metaFlow('ci-pack', ['qa', 'lint']),
        ]);

        // ci-pack → [qa, lint]; explicit qa afterwards must not duplicate.
        $this->assertSame(
            ['qa', 'lint'],
            MultiFlowExpansion::expandFlowNames(['ci-pack', 'qa'], $config)
        );
    }

    // ─── mergeFlowJobRefs: attribute preservation (F2) ───────────────

    /**
     * @test
     * @dataProvider provideSingleAttrFlows
     * @param array<int, string|array<string, mixed>> $flowJobs
     */
    public function merge_preserves_entry_attributes(array $flowJobs, callable $assert): void
    {
        $config = $this->config([
            'qa' => $this->flow('qa', $flowJobs),
        ]);

        $refs = MultiFlowExpansion::mergeFlowJobRefs(['qa'], $config);
        $a = $this->findRef($refs, 'a');

        $this->assertNotNull($a, 'job `a` must be in the merged refs');
        $assert($this, $a);
    }

    /**
     * @return iterable<string, array{0: array<int, mixed>, 1: callable}>
     */
    public function provideSingleAttrFlows(): iterable
    {
        // `needs` carries `b` because the target must be a job of the flow.
        yield 'needs' => [
            ['b', ['job' => 'a', 'needs' => ['b']]],
            function (self $t, $ref): void {
                $t->assertSame(['b'], $ref->getNeeds());
            },
        ];
        yield 'only-files' => [
            [['job' => 'a', 'only-files' => ['src/**']]],
            function (self $t, $ref): void {
                $t->assertSame(['src/**'], $ref->getOnlyFiles());
                $t->assertTrue($ref->hasAdmissionRules());
            },
        ];
        yield 'exclude-files' => [
            [['job' => 'a', 'exclude-files' => ['**/Skip.php']]],
            function (self $t, $ref): void {
                $t->assertSame(['**/Skip.php'], $ref->getExcludeFiles());
                $t->assertTrue($ref->hasAdmissionRules());
            },
        ];
        yield 'plain (no attrs)' => [
            ['a'],
            function (self $t, $ref): void {
                $t->assertSame([], $ref->getNeeds());
                $t->assertFalse($ref->hasAdmissionRules());
            },
        ];
    }

    // ─── mergeFlowJobRefs: first-occurrence-wins dedup (F2 × F3) ──────

    /**
     * The same job (`a`) declared in two flows with different attributes
     * resolves to the JobRef of the FIRST flow it appears in. Covered for every
     * attribute kind and both occurrence orders (attr-first / plain-first).
     *
     * `needs` cases carry an extra `b` because a `needs` target must be a job
     * of its own flow (validated at parse time); the assertion still targets
     * the deduped `a` ref.
     *
     * @test
     * @dataProvider provideDedupCases
     * @param array<int, string|array<string, mixed>> $firstFlowJobs
     * @param array<int, string|array<string, mixed>> $secondFlowJobs
     */
    public function merge_dedups_keeping_first_occurrence_attrs(
        array $firstFlowJobs,
        array $secondFlowJobs,
        callable $assert
    ): void {
        $config = $this->config([
            'first'  => $this->flow('first', $firstFlowJobs),
            'second' => $this->flow('second', $secondFlowJobs),
        ]);

        $refs = MultiFlowExpansion::mergeFlowJobRefs(['first', 'second'], $config);
        $a = $this->findRef($refs, 'a');

        $this->assertNotNull($a, 'job `a` must be present exactly once in the union');
        $assert($this, $a);
    }

    /**
     * @return iterable<string, array{0: array<int, mixed>, 1: array<int, mixed>, 2: callable}>
     */
    public function provideDedupCases(): iterable
    {
        $keepsNeeds = function (self $t, $ref): void {
            $t->assertSame(['b'], $ref->getNeeds(), 'first occurrence declared the need; it survives');
        };
        $dropsNeeds = function (self $t, $ref): void {
            $t->assertSame([], $ref->getNeeds(), 'first occurrence had no needs; the later one is dropped');
        };
        $noAdmission = function (self $t, $ref): void {
            $t->assertFalse($ref->hasAdmissionRules(), 'first occurrence had no rule; the later one is dropped');
        };

        // needs (the declaring flow must also contain the target `b`)
        yield 'needs first, plain second → keeps needs' => [
            ['b', ['job' => 'a', 'needs' => ['b']]], ['a'], $keepsNeeds,
        ];
        yield 'plain first, needs second → drops needs' => [
            ['a'], ['b', ['job' => 'a', 'needs' => ['b']]], $dropsNeeds,
        ];

        // only-files
        yield 'only-files first, plain second → keeps rule' => [
            [['job' => 'a', 'only-files' => ['src/**']]], ['a'],
            function (self $t, $ref): void {
                $t->assertSame(['src/**'], $ref->getOnlyFiles());
            },
        ];
        yield 'plain first, only-files second → drops rule' => [
            ['a'], [['job' => 'a', 'only-files' => ['src/**']]], $noAdmission,
        ];

        // exclude-files
        yield 'exclude-files first, plain second → keeps rule' => [
            [['job' => 'a', 'exclude-files' => ['**/Skip.php']]], ['a'],
            function (self $t, $ref): void {
                $t->assertSame(['**/Skip.php'], $ref->getExcludeFiles());
            },
        ];
        yield 'plain first, exclude-files second → drops rule' => [
            ['a'], [['job' => 'a', 'exclude-files' => ['**/Skip.php']]], $noAdmission,
        ];
    }

    /** @test merged refs keep first-occurrence order across flows */
    public function merge_preserves_first_occurrence_order(): void
    {
        $config = $this->config([
            'qa'   => $this->flow('qa', ['a', 'b']),
            'lint' => $this->flow('lint', ['b', 'c']),
        ]);

        $refs = MultiFlowExpansion::mergeFlowJobRefs(['qa', 'lint'], $config);
        $targets = array_map(fn($r) => $r->getTarget(), $refs);

        $this->assertSame(['a', 'b', 'c'], $targets);
    }

    // ─── buildAggregateGraph (F2 needs × dedup) ──────────────────────

    /** @test the aggregate graph reconstructs declared `needs` */
    public function aggregate_graph_reconstructs_needs(): void
    {
        $config = $this->config([
            'qa' => $this->flow('qa', [
                'b',
                ['job' => 'a', 'needs' => ['b']],
            ]),
        ]);
        $refs = MultiFlowExpansion::mergeFlowJobRefs(['qa'], $config);

        $graph = MultiFlowExpansion::buildAggregateGraph('qa', $refs);

        $this->assertNotNull($graph);
        $this->assertSame(['b'], $graph->getNeedsOf('a'));
        $this->assertSame([], $graph->getNeedsOf('b'));
        $this->assertSame(['b', 'a'], $graph->getOrderedNames(), 'topological order: dependency first');
    }

    /** @test a flow with no `needs` still yields a (dependency-free) graph */
    public function aggregate_graph_without_needs_is_dependency_free(): void
    {
        $config = $this->config([
            'qa' => $this->flow('qa', ['a', 'b']),
        ]);
        $refs = MultiFlowExpansion::mergeFlowJobRefs(['qa'], $config);

        $graph = MultiFlowExpansion::buildAggregateGraph('qa', $refs);

        $this->assertNotNull($graph);
        $this->assertSame([], $graph->getNeedsOf('a'));
        $this->assertSame([], $graph->getNeedsOf('b'));
        $this->assertSame(['a', 'b'], $graph->getOrderedNames());
    }

    /** @test cross-flow needs resolve when the target is in the union (meta-flow) */
    public function aggregate_graph_resolves_needs_across_expanded_meta_flow(): void
    {
        $config = $this->config([
            'build'   => $this->flow('build', ['b']),
            'qa'      => $this->flow('qa', [
                'b',
                ['job' => 'a', 'needs' => ['b']],
            ]),
            'ci-pack' => $this->metaFlow('ci-pack', ['build', 'qa']),
        ]);
        $expanded = MultiFlowExpansion::expandFlowNames(['ci-pack'], $config);
        $refs = MultiFlowExpansion::mergeFlowJobRefs($expanded, $config);

        $graph = MultiFlowExpansion::buildAggregateGraph('ci-pack', $refs);

        $this->assertNotNull($graph);
        $this->assertSame(['b'], $graph->getNeedsOf('a'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param \Wtyd\GitHooks\Configuration\JobRef[] $refs
     */
    private function findRef(array $refs, string $target): ?\Wtyd\GitHooks\Configuration\JobRef
    {
        foreach ($refs as $ref) {
            if ($ref->getTarget() === $target) {
                return $ref;
            }
        }
        return null;
    }

    /**
     * @param array<int, string|array<string, mixed>> $jobEntries
     */
    private function flow(string $name, array $jobEntries): FlowConfiguration
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray(
            $name,
            ['jobs' => $jobEntries],
            ['a', 'b', 'c'],
            $result
        );
        $this->assertNotNull($flow, "fixture flow '$name' failed: " . implode('; ', $result->getErrors()));
        return $flow;
    }

    /**
     * @param string[] $flowRefs
     */
    private function metaFlow(string $name, array $flowRefs): FlowConfiguration
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray(
            $name,
            ['flows' => $flowRefs],
            ['a', 'b', 'c'],
            $result
        );
        $this->assertNotNull($flow, "fixture meta-flow '$name' failed: " . implode('; ', $result->getErrors()));
        return $flow;
    }

    /**
     * @param array<string, FlowConfiguration> $flows
     */
    private function config(array $flows): ConfigurationResult
    {
        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(false, 1),
            [],
            $flows,
            null,
            new ValidationResult()
        );
    }
}
