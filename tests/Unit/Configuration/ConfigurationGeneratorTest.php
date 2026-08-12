<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationGenerator;

/**
 * Direct coverage for the `conf:init` interactive generator (no test existed).
 * The generated string is evaluated as real PHP so the asserts pin the
 * structure a user would actually load — the escaped ConcatOperandRemoval
 * mutant on the job-name prefix (ConfigurationGenerator:73) collapses every
 * job into a duplicate '_src' key, leaving a single-job config.
 */
class ConfigurationGeneratorTest extends UnitTestCase
{
    /** @test */
    public function generated_config_declares_one_typed_job_per_tool_wired_into_the_flow(): void
    {
        $content = (new ConfigurationGenerator())->generate(['phpstan', 'phpcs'], ['src'], ['pre-commit']);

        $config = $this->evaluateGenerated($content);

        $this->assertSame(['phpstan_src', 'phpcs_src'], array_keys($config['jobs']));
        $this->assertSame(['phpstan_src', 'phpcs_src'], $config['flows']['qa']['jobs']);
        $this->assertSame('phpstan', $config['jobs']['phpstan_src']['type']);
        $this->assertSame('phpcs', $config['jobs']['phpcs_src']['type']);
    }

    /** @test */
    public function generated_config_maps_each_hook_event_to_the_qa_flow(): void
    {
        $content = (new ConfigurationGenerator())->generate(['phpstan'], ['src'], ['pre-commit', 'pre-push']);

        $config = $this->evaluateGenerated($content);

        $this->assertSame(['pre-commit' => ['qa'], 'pre-push' => ['qa']], $config['hooks']);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateGenerated(string $phpContent): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'genconf_');
        file_put_contents($tmp, $phpContent);
        try {
            $config = require $tmp;
            $this->assertIsArray($config, 'generated content must evaluate to a config array');
            return $config;
        } finally {
            @unlink($tmp);
        }
    }
}
