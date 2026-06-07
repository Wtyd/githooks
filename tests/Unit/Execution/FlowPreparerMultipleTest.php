<?php

declare(strict_types=1);

namespace Tests\Unit\Execution;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationResult;
use Wtyd\GitHooks\Configuration\FlowConfiguration;
use Wtyd\GitHooks\Configuration\JobConfiguration;
use Wtyd\GitHooks\Configuration\OptionsConfiguration;
use Wtyd\GitHooks\Configuration\ValidationResult;
use Wtyd\GitHooks\Execution\FlowPreparer;
use Wtyd\GitHooks\Jobs\JobRegistry;

class FlowPreparerMultipleTest extends UnitTestCase
{
    private FlowPreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new FlowPreparer(new JobRegistry());
    }

    /**
     * Build a config with three normal flows (qa, lint, deploy) and a meta-flow ci-pack.
     *
     *  - qa     => phpstan_src, phpcs_src
     *  - lint   => phpcs_src, phpmd_src       (phpcs_src shared with qa)
     *  - deploy => phpcpd_src
     *  - ci-pack (meta) => [qa, lint] with options processes=4, fail-fast=true
     */
    private function buildConfig(): ConfigurationResult
    {
        $jobs = [
            'phpstan_src'    => new JobConfiguration('phpstan_src', 'phpstan', ['paths' => ['src']]),
            'phpcs_src'      => new JobConfiguration('phpcs_src', 'phpcs', ['paths' => ['src']]),
            'phpmd_src'      => new JobConfiguration('phpmd_src', 'phpmd', ['paths' => ['src']]),
            'phpcpd_src' => new JobConfiguration('phpcpd_src', 'phpcpd', ['paths' => ['src']]),
        ];

        $flows = [
            'qa'      => new FlowConfiguration('qa', ['phpstan_src', 'phpcs_src']),
            'lint'    => new FlowConfiguration('lint', ['phpcs_src', 'phpmd_src']),
            'deploy'  => new FlowConfiguration('deploy', ['phpcpd_src']),
            'ci-pack' => new FlowConfiguration('ci-pack', [], null, null, ['qa', 'lint']),
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

    /** @test */
    public function ad_hoc_mode_dedups_shared_jobs_keeping_first_occurrence_order()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['qa', 'lint'],
            'qa+lint',
            $config,
            $config->getGlobalOptions()
        );

        $this->assertEquals('qa+lint', $plan->getFlowName());
        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpstan_src', 'phpcs_src', 'phpmd_src'], $jobNames);
        $this->assertEquals(['qa', 'lint'], $plan->getExpandedFlows());
    }

    /** @test */
    public function declarative_mode_expands_meta_flow_into_referenced_flows()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['ci-pack'],
            'ci-pack',
            $config,
            $config->getGlobalOptions()
        );

        $this->assertEquals('ci-pack', $plan->getFlowName());
        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpstan_src', 'phpcs_src', 'phpmd_src'], $jobNames);
        $this->assertEquals(['qa', 'lint'], $plan->getExpandedFlows());
    }

    /** @test */
    public function mixed_mode_appends_normal_flow_after_meta_flow_expansion()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['ci-pack', 'deploy'],
            'ci-pack+deploy',
            $config,
            $config->getGlobalOptions()
        );

        $this->assertEquals(['qa', 'lint', 'deploy'], $plan->getExpandedFlows());
        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpstan_src', 'phpcs_src', 'phpmd_src', 'phpcpd_src'], $jobNames);
    }

    /** @test */
    public function repeated_args_are_deduped_silently()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['qa', 'qa'],
            'qa',
            $config,
            $config->getGlobalOptions()
        );

        $this->assertEquals(['qa'], $plan->getExpandedFlows());
        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpstan_src', 'phpcs_src'], $jobNames);
    }

    /** @test */
    public function exclude_jobs_filters_the_merged_union()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['qa', 'lint'],
            'qa+lint',
            $config,
            $config->getGlobalOptions(),
            null,
            ['phpcs_src']
        );

        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpstan_src', 'phpmd_src'], $jobNames);
    }

    /** @test */
    public function only_jobs_restricts_the_merged_union()
    {
        $config = $this->buildConfig();

        $plan = $this->preparer->prepareMultiple(
            ['qa', 'lint'],
            'qa+lint',
            $config,
            $config->getGlobalOptions(),
            null,
            [],
            ['phpmd_src']
        );

        $jobNames = array_map(fn($j) => $j->getName(), $plan->getJobs());
        $this->assertEquals(['phpmd_src'], $jobNames);
    }

    /** @test */
    public function options_passed_to_prepare_multiple_propagate_to_the_plan()
    {
        $config = $this->buildConfig();
        $resolved = new OptionsConfiguration(true, 8);

        $plan = $this->preparer->prepareMultiple(
            ['qa', 'lint'],
            'qa+lint',
            $config,
            $resolved
        );

        $this->assertTrue($plan->getOptions()->isFailFast());
        $this->assertEquals(8, $plan->getOptions()->getProcesses());
    }

    /** @test */
    public function meta_flow_with_overlapping_normal_flow_dedups_at_flow_level()
    {
        $config = $this->buildConfig();

        // ci-pack expands to [qa, lint]; passing 'qa' explicitly afterwards must not
        // produce a duplicate flow name in expandedFlows.
        $plan = $this->preparer->prepareMultiple(
            ['ci-pack', 'qa'],
            'ci-pack+qa',
            $config,
            $config->getGlobalOptions()
        );

        $this->assertEquals(['qa', 'lint'], $plan->getExpandedFlows());
    }
}
