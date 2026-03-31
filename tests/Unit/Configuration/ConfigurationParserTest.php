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
        $this->assertEquals(['lint'], $result->getHooks()->resolve('pre-commit'));
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
}
