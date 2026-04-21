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

    /**
     * @test
     * Kills L53 LogicalAnd→Or: `$toolName === 'script' && isset($toolConfig['name'])`
     * flipped to `||` would rewrite a non-script tool carrying a `name` key as a
     * custom job. Non-script tools with any key must keep their original type.
     */
    public function it_preserves_type_when_non_script_tool_has_a_name_key()
    {
        $legacy = [
            'Tools'   => ['phpstan'],
            'phpstan' => ['name' => 'lint_name', 'paths' => ['src']],
        ];

        $output = $this->migrator->migrate($legacy);
        $result = $this->evalConfig($output);

        $this->assertSame('phpstan', $result['jobs']['phpstan']['type']);
        $this->assertArrayNotHasKey('name', $result['jobs']['phpstan']);
    }

    /**
     * @test
     * Kills L62 ReturnRemoval: without the early return the script branch
     * would fall through to the generic foreach and duplicate `name`,
     * `executablePath`, `otherArguments` keys on top of the `script` entry.
     */
    public function it_does_not_leak_script_source_keys_into_custom_job_entry()
    {
        $legacy = [
            'Tools'  => ['script'],
            'script' => [
                'name'           => 'my-lint',
                'executablePath' => 'node_modules/.bin/eslint',
                'otherArguments' => '--fix',
            ],
        ];

        $output = $this->migrator->migrate($legacy);
        $result = $this->evalConfig($output);

        $entry = $result['jobs']['my_lint'] ?? $result['jobs']['script'];
        $this->assertSame('custom', $entry['type']);
        $this->assertSame('node_modules/.bin/eslint --fix', $entry['script']);
        $this->assertArrayNotHasKey('executablePath', $entry);
        $this->assertArrayNotHasKey('otherArguments', $entry);
        $this->assertArrayNotHasKey('name', $entry);
    }

    /**
     * @test
     * Kills L66 Foreach_→[]: emptying the generic copy loop would drop every
     * tool-specific key. The migrated entry must carry paths, config and level.
     */
    public function it_copies_all_tool_specific_keys_into_the_migrated_entry()
    {
        $legacy = [
            'Tools'   => ['phpstan'],
            'phpstan' => [
                'config' => 'qa/phpstan.neon',
                'paths'  => ['src'],
                'level'  => 8,
            ],
        ];

        $output = $this->migrator->migrate($legacy);
        $result = $this->evalConfig($output);

        $entry = $result['jobs']['phpstan'];
        $this->assertSame('phpstan', $entry['type']);
        $this->assertSame('qa/phpstan.neon', $entry['config']);
        $this->assertSame(['src'], $entry['paths']);
        $this->assertSame(8, $entry['level']);
    }

    /**
     * @test
     * Kills L71 Identical `===`→`!==`: flipping the filter would keep
     * `usePhpcsConfiguration` (a v2-only legacy key that v3 rejects) and drop
     * every other key. Migrated entry must never contain it while keeping
     * other keys intact.
     */
    public function it_strips_use_phpcs_configuration_from_migrated_entry()
    {
        $legacy = [
            'Tools' => ['phpcs'],
            'phpcs' => [
                'standard'              => 'PSR12',
                'ignore'                => ['vendor', 'tools'],
                'usePhpcsConfiguration' => true,
            ],
        ];

        $output = $this->migrator->migrate($legacy);
        $result = $this->evalConfig($output);

        $entry = $result['jobs']['phpcs'];
        $this->assertSame('PSR12', $entry['standard']);
        $this->assertSame(['vendor', 'tools'], $entry['ignore']);
        $this->assertArrayNotHasKey('usePhpcsConfiguration', $entry);
    }

    /**
     * @test
     * Kills L105 Foreach_→[]: emptying the jobs foreach in the `qa` flow
     * would leave an empty jobs array. The flow must list every migrated job
     * in the same order as the original Tools section.
     */
    public function it_lists_every_migrated_job_in_the_qa_flow_in_order()
    {
        $legacy = [
            'Tools'   => ['phpstan', 'phpcs', 'phpmd'],
            'phpstan' => ['paths' => ['src']],
            'phpcs'   => ['standard' => 'PSR12'],
            'phpmd'   => ['paths' => ['src'], 'rules' => 'cleancode'],
        ];

        $output = $this->migrator->migrate($legacy);
        $result = $this->evalConfig($output);

        $this->assertSame(
            ['phpstan', 'phpcs', 'phpmd'],
            $result['flows']['qa']['jobs']
        );
    }

    /**
     * @test
     * Kills L140 FunctionCall var_export→null: removing `var_export` inside
     * the array-rendering branch would emit `NULL` for every element. The
     * migrated PHP must evaluate to arrays whose literal values are
     * round-tripped intact.
     */
    public function it_renders_array_values_with_exact_literal_items()
    {
        $legacy = [
            'Tools'   => ['phpstan'],
            'phpstan' => [
                'paths'        => ['src', 'app'],
                'ignoreErrors' => ['SomeError', 'AnotherError'],
            ],
        ];

        $output = $this->migrator->migrate($legacy);

        // The rendered literals must be present verbatim — not as NULL.
        $this->assertStringContainsString("'SomeError'", $output);
        $this->assertStringContainsString("'AnotherError'", $output);
        $this->assertStringNotContainsString('NULL', $output);

        $result = $this->evalConfig($output);
        $this->assertSame(['src', 'app'], $result['jobs']['phpstan']['paths']);
        $this->assertSame(['SomeError', 'AnotherError'], $result['jobs']['phpstan']['ignoreErrors']);
    }

    /**
     * Evaluate the migrator output as PHP and return the resulting config array.
     *
     * @return array<string, mixed>
     */
    private function evalConfig(string $phpSource): array
    {
        $tmpFile = sys_get_temp_dir() . '/githooks_migrator_test_' . uniqid() . '.php';
        file_put_contents($tmpFile, $phpSource);
        try {
            return require $tmpFile;
        } finally {
            @unlink($tmpFile);
        }
    }
}
