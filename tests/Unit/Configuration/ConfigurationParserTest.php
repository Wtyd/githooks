<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationParser;
use Wtyd\GitHooks\Registry\ToolRegistry;

class ConfigurationParserTest extends TestCase
{
    private string $fixturesPath;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->fixturesPath = sys_get_temp_dir() . '/githooks_config_test_' . uniqid();
        mkdir($this->fixturesPath, 0755, true);
        $this->registry = new ToolRegistry();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixturesPath . '/*') ?: []);
        @rmdir($this->fixturesPath);
    }

    /** @test */
    public function it_detects_legacy_format()
    {
        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);

        $this->assertTrue($parser->isLegacyFormat([
            'Options' => ['execution' => 'full'],
            'Tools'   => ['phpstan'],
        ]));

        $this->assertFalse($parser->isLegacyFormat([
            'hooks' => ['pre-commit' => ['lint']],
            'flows' => ['lint' => ['jobs' => ['phpstan_src']]],
            'jobs'  => ['phpstan_src' => ['type' => 'phpstan']],
        ]));
    }

    /** @test */
    public function it_parses_a_complete_v3_config()
    {
        $config = <<<'PHP'
<?php
return [
    'hooks' => [
        'pre-commit' => ['lint'],
    ],
    'flows' => [
        'options' => ['fail-fast' => false, 'processes' => 2],
        'lint' => [
            'options' => ['fail-fast' => true],
            'jobs'    => ['phpcs_src', 'phpmd_src'],
        ],
    ],
    'jobs' => [
        'phpcs_src' => [
            'type'     => 'phpcs',
            'paths'    => ['src'],
            'standard' => 'PSR12',
        ],
        'phpmd_src' => [
            'type'  => 'phpmd',
            'paths' => ['src'],
            'rules' => 'cleancode',
        ],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->isLegacy());
        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertCount(2, $result->getJobs());
        $this->assertCount(1, $result->getFlows());
        $this->assertNotNull($result->getHooks());
        $refs = $result->getHooks()->resolve('pre-commit');
        $this->assertEquals('lint', $refs[0]->getTarget());
        $this->assertEquals(2, $result->getGlobalOptions()->getProcesses());

        $lintFlow = $result->getFlow('lint');
        $this->assertNotNull($lintFlow);
        $this->assertTrue($lintFlow->getOptions()->isFailFast());
        $this->assertEquals(['phpcs_src', 'phpmd_src'], $lintFlow->getJobs());
    }

    /** @test */
    public function it_returns_legacy_result_for_old_format()
    {
        $config = <<<'PHP'
<?php
return [
    'Options' => ['execution' => 'full'],
    'Tools'   => ['phpstan'],
    'phpstan' => ['paths' => ['src']],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertTrue($result->isLegacy());
        $this->assertNotNull($result->getLegacyConfig());
        $this->assertCount(1, $result->getValidation()->getWarnings());
        $this->assertStringContainsString('Legacy', $result->getValidation()->getWarnings()[0]);
    }

    /** @test */
    public function it_reports_errors_for_invalid_v3_config()
    {
        $config = <<<'PHP'
<?php
return [
    'hooks' => [
        'not-a-hook' => ['lint'],
    ],
    'flows' => [
        'lint' => ['jobs' => ['missing_job']],
    ],
    'jobs' => [],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertTrue($result->hasErrors());
        $errors = $result->getValidation()->getErrors();
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    /** @test */
    public function it_parses_config_without_hooks_section()
    {
        $config = <<<'PHP'
<?php
return [
    'flows' => [
        'lint' => ['jobs' => ['phpcs_src']],
    ],
    'jobs' => [
        'phpcs_src' => ['type' => 'phpcs', 'paths' => ['src']],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertNull($result->getHooks());
        $this->assertCount(1, $result->getFlows());
    }

    /** @test */
    public function it_supports_custom_job_type()
    {
        $config = <<<'PHP'
<?php
return [
    'flows' => [
        'ci' => ['jobs' => ['lint_js']],
    ],
    'jobs' => [
        'lint_js' => [
            'type'   => 'custom',
            'script' => 'npm run lint',
        ],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $job = $result->getJob('lint_js');
        $this->assertNotNull($job);
        $this->assertEquals('custom', $job->getType());
        $this->assertEquals('npm run lint', $job->getConfig()['script']);
    }

    /**
     * @test
     * Unsupported file extension triggers InvalidArgumentException.
     */
    public function it_throws_for_unsupported_file_extension()
    {
        $badFile = $this->fixturesPath . '/githooks.toml';
        file_put_contents($badFile, 'key = "value"');

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);

        $this->expectException(\InvalidArgumentException::class);
        $parser->parse($badFile);
    }

    /** @test */
    public function it_reports_type_error_without_confusing_undefined_warning()
    {
        $config = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['bad_job']],
    ],
    'jobs' => [
        'bad_job' => ['type' => 'nonexistent_tool'],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        // Should have the type error
        $this->assertTrue($result->hasErrors());
        $typeError = false;
        foreach ($result->getValidation()->getErrors() as $error) {
            if (strpos($error, 'not a supported tool') !== false) {
                $typeError = true;
            }
        }
        $this->assertTrue($typeError, 'Expected error about unsupported tool type');

        // Bug #9 fix: should NOT have warning about "undefined job"
        foreach ($result->getValidation()->getWarnings() as $warning) {
            $this->assertStringNotContainsString(
                'references undefined job',
                $warning,
                'Should not warn about undefined job when the job has a type error'
            );
        }
    }

    /** @test */
    public function it_treats_empty_array_as_v3_with_errors()
    {
        $config = '<?php return [];';
        file_put_contents($this->fixturesPath . '/githooks.php', $config);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        // Empty config should NOT be detected as legacy
        $this->assertFalse($result->isLegacy());
        // But should have errors (no jobs section)
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_throws_friendly_error_for_nonexistent_file()
    {
        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);

        $this->expectException(\Wtyd\GitHooks\ConfigurationFile\Exception\ConfigurationFileNotFoundException::class);
        $this->expectExceptionMessage('Configuration file not found');
        $parser->parse($this->fixturesPath . '/does_not_exist.php');
    }

    // ========================================================================
    // Local override (githooks.local.php)
    // ========================================================================

    /** @test */
    public function it_merges_local_override_replacing_scalar_string()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['main-branch' => 'master'],
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['main-branch' => 'develop'],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertEquals('develop', $result->getGlobalOptions()->getMainBranch());
    }

    /** @test */
    public function it_merges_local_override_replacing_scalar_int()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['processes' => 1],
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['processes' => 8],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertEquals(8, $result->getGlobalOptions()->getProcesses());
    }

    /** @test */
    public function it_merges_local_override_replacing_scalar_bool()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['fail-fast' => false],
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['fail-fast' => true],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertTrue($result->getGlobalOptions()->isFailFast());
    }

    /** @test */
    public function it_merges_local_override_adding_new_option()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['processes' => 2],
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'flows' => [
        'options' => ['executable-prefix' => 'docker exec -i app'],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertEquals('docker exec -i app', $result->getGlobalOptions()->getExecutablePrefix());
        $this->assertEquals(2, $result->getGlobalOptions()->getProcesses());
    }

    /** @test */
    public function it_merges_local_override_replacing_nested_job_property()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src'], 'level' => '5'],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'jobs' => [
        'phpstan_src' => ['level' => '8'],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $job = $result->getJob('phpstan_src');
        $this->assertNotNull($job);
        $this->assertEquals('8', $job->getConfig()['level']);
        $this->assertEquals(['src'], $job->getPaths());
    }

    /** @test */
    public function it_merges_local_override_adding_new_job()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src', 'phpcs_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'jobs' => [
        'phpcs_src' => ['type' => 'phpcs', 'paths' => ['src']],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertCount(2, $result->getJobs());
        $this->assertNotNull($result->getJob('phpstan_src'));
        $this->assertNotNull($result->getJob('phpcs_src'));
    }

    /** @test */
    public function it_merges_local_override_indexed_array_replaces_by_index()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src', 'tests']],
    ],
];
PHP;
        $local = <<<'PHP'
<?php
return [
    'jobs' => [
        'phpstan_src' => ['paths' => ['app']],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $job = $result->getJob('phpstan_src');
        // array_replace_recursive replaces by index: index 0 → 'app', index 1 → 'tests' (kept)
        $this->assertEquals(['app', 'tests'], $job->getPaths());
    }

    /** @test */
    public function it_works_without_local_override_file()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertFalse($result->hasErrors(), implode("\n", $result->getValidation()->getErrors()));
        $this->assertNull($result->getLocalFilePath());
    }

    /** @test */
    public function it_does_not_look_for_local_override_on_yaml_files()
    {
        $yml = "flows:\n  qa:\n    jobs:\n      - phpstan_src\njobs:\n  phpstan_src:\n    type: phpstan\n    paths:\n      - src\n";
        $local = '<?php return ["flows" => ["options" => ["processes" => 99]]];';

        file_put_contents($this->fixturesPath . '/githooks.yml', $yml);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        // Local override should be ignored for YAML files
        $this->assertNull($result->getLocalFilePath());
        $this->assertEquals(1, $result->getGlobalOptions()->getProcesses());
    }

    /** @test */
    public function it_stores_local_file_path_in_result()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        $local = '<?php return [];';

        file_put_contents($this->fixturesPath . '/githooks.php', $main);
        file_put_contents($this->fixturesPath . '/githooks.local.php', $local);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertNotNull($result->getLocalFilePath());
        $this->assertStringContainsString('githooks.local.php', $result->getLocalFilePath());
    }

    /** @test */
    public function it_reports_null_local_path_when_no_local_file()
    {
        $main = <<<'PHP'
<?php
return [
    'flows' => [
        'qa' => ['jobs' => ['phpstan_src']],
    ],
    'jobs' => [
        'phpstan_src' => ['type' => 'phpstan', 'paths' => ['src']],
    ],
];
PHP;
        file_put_contents($this->fixturesPath . '/githooks.php', $main);

        $parser = new ConfigurationParser($this->registry, $this->fixturesPath);
        $result = $parser->parse();

        $this->assertNull($result->getLocalFilePath());
    }
}
