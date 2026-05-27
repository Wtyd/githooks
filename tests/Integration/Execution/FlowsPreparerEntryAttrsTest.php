<?php

declare(strict_types=1);

namespace Tests\Integration\Execution;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;

/**
 * FEAT-1 / FEAT-3 — `flows` (prepareMultiple) must preserve the flow-entry
 * attributes that `flow` (prepare) already honours: the dependency graph
 * (`needs`) and the admission rules (`only-files` / `exclude-files`).
 *
 * Before the fix, prepareMultiple flattened the merged jobs to plain strings,
 * so the aggregate FlowConfiguration lost every JobRef attribute and its
 * dependency graph was null. These tests pin the preparer seam: the FlowPlan
 * returned by prepareMultiple must carry a graph whose `needs` mirror the
 * declarations, with first-occurrence-wins dedup across flows.
 */
class FlowsPreparerEntryAttrsTest extends TestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /** @test single normal flow keeps its dependency graph through prepareMultiple */
    public function single_flow_preserves_dependency_graph(): void
    {
        $config = $this->configWith([
            'qa' => $this->flow('qa', [
                'prepare',
                ['job' => 'tests', 'needs' => ['prepare']],
            ]),
        ]);

        $plan = $this->preparer->prepareMultiple(
            ['qa'],
            'qa',
            $config,
            $config->getGlobalOptions()
        );

        $graph = $plan->getDependencyGraph();
        $this->assertNotNull($graph, 'aggregate must carry a dependency graph');
        $this->assertSame(['prepare'], $graph->getNeedsOf('tests'));
        $this->assertSame([], $graph->getNeedsOf('prepare'));
    }

    /** @test the graph reorders the merged jobs topologically (needs declared out of order) */
    public function dependency_graph_topologically_orders_merged_jobs(): void
    {
        $config = $this->configWith([
            'qa' => $this->flow('qa', [
                ['job' => 'tests', 'needs' => ['prepare']],
                'prepare',
            ]),
        ]);

        $plan = $this->preparer->prepareMultiple(
            ['qa'],
            'qa',
            $config,
            $config->getGlobalOptions()
        );

        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertSame(['prepare', 'tests'], $jobNames, 'prepare must precede its dependent');
    }

    /** @test needs across two flows resolve when the target is present in the union */
    public function multi_flow_preserves_intra_flow_needs(): void
    {
        $config = $this->configWith([
            'build' => $this->flow('build', ['prepare']),
            'qa'    => $this->flow('qa', [
                'prepare',
                ['job' => 'tests', 'needs' => ['prepare']],
            ]),
        ]);

        $plan = $this->preparer->prepareMultiple(
            ['build', 'qa'],
            'build+qa',
            $config,
            $config->getGlobalOptions()
        );

        $graph = $plan->getDependencyGraph();
        $this->assertNotNull($graph);
        $this->assertSame(['prepare'], $graph->getNeedsOf('tests'));
    }

    /**
     * @test first-occurrence-wins: when a job appears in two flows with
     *       different attrs, the aggregate keeps the attrs of the FIRST one.
     */
    public function dedup_keeps_first_occurrence_attrs(): void
    {
        // `tests` appears first as a plain ref (no needs), then with needs.
        $config = $this->configWith([
            'first'  => $this->flow('first', ['tests']),
            'second' => $this->flow('second', [
                'prepare',
                ['job' => 'tests', 'needs' => ['prepare']],
            ]),
        ]);

        $plan = $this->preparer->prepareMultiple(
            ['first', 'second'],
            'first+second',
            $config,
            $config->getGlobalOptions()
        );

        $graph = $plan->getDependencyGraph();
        $this->assertNotNull($graph);
        $this->assertSame(
            [],
            $graph->getNeedsOf('tests'),
            'first occurrence (plain) wins: the later needs declaration is dropped'
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param array<int, string|array<string, mixed>> $jobEntries
     */
    private function flow(string $name, array $jobEntries): FlowConfiguration
    {
        $result = new ValidationResult();
        $flow = FlowConfiguration::fromArray(
            $name,
            ['jobs' => $jobEntries],
            ['prepare', 'tests'],
            $result
        );
        $this->assertNotNull($flow, 'fixture flow build failed: ' . implode('; ', $result->getErrors()));
        return $flow;
    }

    /**
     * @param array<string, FlowConfiguration> $flows
     */
    private function configWith(array $flows): ConfigurationResult
    {
        $jobs = [
            'prepare' => new JobConfiguration('prepare', 'custom', ['script' => 'true']),
            'tests'   => new JobConfiguration('tests', 'custom', ['script' => 'true']),
        ];

        return new ConfigurationResult(
            'githooks.php',
            new OptionsConfiguration(false, 1),
            $jobs,
            $flows,
            null,
            new ValidationResult()
        );
    }
}
