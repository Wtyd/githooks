<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Configuration\ConfigurationMigrator;

class ConfigurationMigratorTest extends TestCase
{
    private ConfigurationMigrator $migrator;

    protected function setUp(): void
    {
        $this->migrator = new ConfigurationMigrator();
    }

    /** @test */
    public function it_migrates_basic_v2_config_to_v3()
    {
        $legacy = [
            'Options' => ['execution' => 'full', 'processes' => 2],
            'Tools' => ['phpstan', 'phpcs'],
            'phpstan' => ['config' => 'phpstan.neon', 'paths' => ['src']],
            'phpcs' => ['standard' => 'PSR12'],
        ];

        $output = $this->migrator->migrate($legacy);

        $this->assertStringContainsString("'hooks'", $output);
        $this->assertStringContainsString("'flows'", $output);
        $this->assertStringContainsString("'jobs'", $output);
        $this->assertStringContainsString("'phpstan'", $output);
        $this->assertStringContainsString("'phpcs'", $output);
        $this->assertStringContainsString("'type' => 'phpstan'", $output);
        $this->assertStringContainsString("'type' => 'phpcs'", $output);
    }

    /** @test */
    public function it_converts_script_tool_to_custom_job()
    {
        $legacy = [
            'Tools' => ['script'],
            'script' => [
                'name' => 'my-lint',
                'executablePath' => 'node_modules/.bin/eslint',
                'otherArguments' => '--fix',
            ],
        ];

        $output = $this->migrator->migrate($legacy);

        $this->assertStringContainsString("'type' => 'custom'", $output);
        $this->assertStringContainsString("'script' => 'node_modules/.bin/eslint --fix'", $output);
    }

    /** @test */
    public function it_preserves_processes_value()
    {
        $legacy = [
            'Options' => ['processes' => 8],
            'Tools' => ['phpstan'],
            'phpstan' => [],
        ];

        $output = $this->migrator->migrate($legacy);

        $this->assertStringContainsString("'processes' => 8", $output);
    }

    /** @test */
    public function it_produces_valid_php_output()
    {
        $legacy = [
            'Options' => ['execution' => 'full'],
            'Tools' => ['phpstan'],
            'phpstan' => ['paths' => ['src']],
        ];

        $output = $this->migrator->migrate($legacy);

        // Must start with <?php
        $this->assertStringStartsWith('<?php', $output);

        // Must be eval-able without errors
        $tmpFile = sys_get_temp_dir() . '/githooks_migrator_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, $output);
        $result = require $tmpFile;
        unlink($tmpFile);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('hooks', $result);
        $this->assertArrayHasKey('flows', $result);
        $this->assertArrayHasKey('jobs', $result);
    }
}
