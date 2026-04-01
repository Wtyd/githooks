<?php

namespace Tests\System;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Utils\TestCase\SystemTestCase;

class GitHooksDistYmlTest extends SystemTestCase
{
    protected function readDistFile(): array
    {
        $distFile = Storage::get('qa/githooks.dist.yml');
        // Strip the header comment block (lines starting with #) before any YAML content
        $lines = explode("\n", $distFile);
        $yamlLines = [];
        $headerDone = false;
        foreach ($lines as $line) {
            if (!$headerDone && (trim($line) === '' || strpos(ltrim($line), '#') === 0)) {
                continue;
            }
            $headerDone = true;
            // Uncomment commented-out YAML keys (e.g. "  # phpstan_src:")
            $yamlLines[] = preg_replace('/^(\s*)# /', '$1', $line);
        }
        return Yaml::parse(implode("\n", $yamlLines));
    }

    /** @test */
    function it_checks_dist_configuration_file_has_flows_section()
    {
        $distFile = $this->readDistFile();

        $this->assertArrayHasKey('flows', $distFile);
        $this->assertArrayHasKey('options', $distFile['flows']);
        $this->assertArrayHasKey('fail-fast', $distFile['flows']['options']);
        $this->assertArrayHasKey('processes', $distFile['flows']['options']);
    }

    /** @test */
    function it_checks_dist_configuration_file_has_jobs_section()
    {
        $distFile = $this->readDistFile();

        $this->assertArrayHasKey('jobs', $distFile);
        $this->assertNotEmpty($distFile['jobs']);
    }

    /** @test */
    function it_checks_dist_configuration_file_has_at_least_one_flow_with_jobs()
    {
        $distFile = $this->readDistFile();

        $flows = $distFile['flows'];
        unset($flows['options']);
        $this->assertNotEmpty($flows, 'Dist file must define at least one flow');
        foreach ($flows as $flowName => $flowConfig) {
            $this->assertArrayHasKey('jobs', $flowConfig, "Flow '$flowName' is missing 'jobs' key");
        }
    }

    /** @test */
    function it_checks_dist_jobs_have_type_key()
    {
        $distFile = $this->readDistFile();

        foreach ($distFile['jobs'] as $jobName => $jobConfig) {
            $this->assertArrayHasKey('type', $jobConfig, "Job '$jobName' is missing 'type' key");
        }
    }
}
