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

    /**
     * `paths` is emitted for every job EXCEPT phpunit, which is driven by its
     * own configuration file. A fixture without phpunit cannot tell that rule
     * from its negation — under the flipped guard phpstan and phpcs silently
     * lose their paths and the generated config analyses nothing.
     *
     * @test
     */
    public function generated_config_gives_paths_to_every_job_but_phpunit(): void
    {
        $content = (new ConfigurationGenerator())->generate(['phpstan', 'phpunit'], ['src'], ['pre-commit']);

        $config = $this->evaluateGenerated($content);

        $this->assertSame(['src'], $config['jobs']['phpstan_src']['paths']);
        $this->assertArrayNotHasKey('paths', $config['jobs']['phpunit_src']);
    }

    /**
     * Two paths, neither of them `src`. The single-path fixture used by the
     * other tests is the `??` fallback AND a one-element array at the same
     * time, so it cannot tell the real value from the default, nor the
     * one-element branch of the array renderer from the multi one.
     *
     * @test
     */
    public function generated_config_names_jobs_after_the_first_path_and_renders_them_all(): void
    {
        $content = (new ConfigurationGenerator())->generate(['phpstan'], ['app', 'lib'], ['pre-commit']);

        $config = $this->evaluateGenerated($content);

        $this->assertSame(['phpstan_app'], array_keys($config['jobs']));
        $this->assertSame(['app', 'lib'], $config['jobs']['phpstan_app']['paths']);
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
