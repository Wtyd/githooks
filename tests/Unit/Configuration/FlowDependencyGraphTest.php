<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssertWarningsTrait;
use Wtyd\GitHooks\Configuration\FlowDependencyGraph;
use Wtyd\GitHooks\Configuration\JobRef;
use Wtyd\GitHooks\Configuration\ValidationResult;

/**
 * FEAT-3 · Group B — DAG validation (cycles, missing target, topo sort, descendants).
 *
 * The graph encapsulates static validation of the `needs` cross-job relations:
 * unknown targets, cycles of any length (n ≥ 1 including self-loop), duplicate
 * job declarations, and the topological order used by the executor.
 */
class FlowDependencyGraphTest extends TestCase
{
    use AssertWarningsTrait;

    /** @test */
    public function B0_simple_dag_returns_stable_topological_order()
    {
        $refs = [
            $this->ref('yarn-install'),
            $this->ref('eslint', ['yarn-install']),
            $this->ref('phpstan'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertSame(['yarn-install', 'eslint', 'phpstan'], $graph->getOrderedNames());
    }

    /** @test */
    public function B1_needs_referencing_undefined_job_returns_error()
    {
        $refs = [
            $this->ref('eslint', ['yarn-install']),  // yarn-install not declared
        ];
        $result = new ValidationResult();

        $graph = FlowDependencyGraph::build('qa', $refs, $result);

        $this->assertNull($graph);
        $this->assertErrorEquals(
            "Flow 'qa' job ref 'eslint': 'needs' references undefined job 'yarn-install'.",
            $result
        );
    }

    /** @test */
    public function B2_cycle_of_two_returns_error_with_chain()
    {
        // A → B → A
        $refs = [
            $this->ref('A', ['B']),
            $this->ref('B', ['A']),
        ];
        $result = new ValidationResult();

        $graph = FlowDependencyGraph::build('qa', $refs, $result);

        $this->assertNull($graph);
        $this->assertErrorEquals(
            "Flow 'qa': 'needs' has a cycle: A -> B -> A.",
            $result
        );
    }

    /** @test */
    public function B3_cycle_of_three_returns_error_with_full_chain()
    {
        // A → B → C → A
        $refs = [
            $this->ref('A', ['C']),  // A needs C
            $this->ref('B', ['A']),  // B needs A
            $this->ref('C', ['B']),  // C needs B → cycle
        ];
        $result = new ValidationResult();

        $graph = FlowDependencyGraph::build('qa', $refs, $result);

        $this->assertNull($graph);
        $this->assertStringContainsString(
            "Flow 'qa': 'needs' has a cycle:",
            $result->getErrors()[0]
        );
        // The cycle text must include all three nodes
        foreach (['A', 'B', 'C'] as $node) {
            $this->assertStringContainsString($node, $result->getErrors()[0]);
        }
    }

    /** @test */
    public function B3b_self_loop_is_detected_as_cycle_of_length_one()
    {
        // A → A
        $refs = [
            $this->ref('A', ['A']),
        ];
        $result = new ValidationResult();

        $graph = FlowDependencyGraph::build('qa', $refs, $result);

        $this->assertNull($graph);
        $this->assertErrorEquals(
            "Flow 'qa': 'needs' has a cycle: A -> A.",
            $result
        );
    }

    /** @test */
    public function B4_valid_dag_without_cycles_returns_graph()
    {
        $refs = [
            $this->ref('yarn'),
            $this->ref('eslint', ['yarn']),
            $this->ref('prettier', ['yarn']),
            $this->ref('phpstan'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertNotNull($graph);
    }

    /** @test */
    public function B5_duplicate_job_declaration_returns_error()
    {
        $refs = [
            $this->ref('eslint'),
            $this->ref('eslint', ['yarn']),
        ];
        $result = new ValidationResult();

        $graph = FlowDependencyGraph::build('qa', $refs, $result);

        $this->assertNull($graph);
        $this->assertErrorEquals(
            "Flow 'qa': job 'eslint' is declared more than once.",
            $result
        );
    }

    /** @test */
    public function C1_topological_order_respects_dependencies()
    {
        $refs = [
            $this->ref('yarn'),
            $this->ref('eslint', ['yarn']),
            $this->ref('prettier', ['yarn']),
            $this->ref('phpstan'),
            $this->ref('phpcs'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);
        $order = $graph->getOrderedNames();

        // yarn must come before eslint/prettier
        $this->assertLessThan(array_search('eslint', $order, true), array_search('yarn', $order, true));
        $this->assertLessThan(array_search('prettier', $order, true), array_search('yarn', $order, true));
    }

    /** @test */
    public function C2_stable_sort_preserves_declaration_order_among_unrelated_nodes()
    {
        // No dependencies — order must equal declaration order
        $refs = [
            $this->ref('a'),
            $this->ref('b'),
            $this->ref('c'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertSame(['a', 'b', 'c'], $graph->getOrderedNames());
    }

    /** @test */
    public function C3_no_needs_anywhere_preserves_declaration_order()
    {
        $refs = [
            $this->ref('phpstan'),
            $this->ref('phpcs'),
            $this->ref('phpmd'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertSame(['phpstan', 'phpcs', 'phpmd'], $graph->getOrderedNames());
    }

    /** @test */
    public function descendantsOf_returns_transitive_dependents()
    {
        // yarn → eslint → lint-fix; phpstan independiente
        $refs = [
            $this->ref('yarn'),
            $this->ref('eslint', ['yarn']),
            $this->ref('lint-fix', ['eslint']),
            $this->ref('phpstan'),
        ];

        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertEqualsCanonicalizing(
            ['eslint', 'lint-fix'],
            $graph->descendantsOf('yarn')
        );
        $this->assertEqualsCanonicalizing(
            ['lint-fix'],
            $graph->descendantsOf('eslint')
        );
        $this->assertSame([], $graph->descendantsOf('phpstan'));
        $this->assertSame([], $graph->descendantsOf('lint-fix'));
    }

    /** @test */
    public function descendantsOf_unknown_job_returns_empty()
    {
        $refs = [$this->ref('a')];
        $graph = $this->buildExpectNoErrors('qa', $refs);

        $this->assertSame([], $graph->descendantsOf('nonexistent'));
    }

    /**
     * @param string[] $needs
     */
    private function ref(string $name, array $needs = []): JobRef
    {
        return new JobRef($name, null, null, $needs);
    }

    /**
     * @param JobRef[] $refs
     */
    private function buildExpectNoErrors(string $flowName, array $refs): FlowDependencyGraph
    {
        $result = new ValidationResult();
        $graph = FlowDependencyGraph::build($flowName, $refs, $result);

        $this->assertFalse(
            $result->hasErrors(),
            'Unexpected errors: ' . implode("\n", $result->getErrors())
        );
        $this->assertNotNull($graph);
        return $graph;
    }
}
