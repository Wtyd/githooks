<?php

declare(strict_types=1);

namespace Tests\System\Release;

use Tests\ReleaseTestCase;

/**
 * @group release
 */
class MigrateConfigurationReleaseTest extends ReleaseTestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configPath = self::TESTS_PATH . '/githooks.php';
    }

    /** @test */
    public function it_migrates_v2_config_successfully()
    {
        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildPhp()
        );

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Migrated to v3', $this->getActualOutput());
        $this->assertFileExists($this->configPath . '.v2.bak');
    }

    /**
     * The v2 `script` tool is listed in `Tools` by its alias while its config
     * lives under the `script` key. The migrator looked the alias up as a tool
     * name, emitted `type: '<alias>'` — unsupported — and dropped the command,
     * so the migrated file was rejected by every later command. The binary must
     * produce a configuration that `conf:check` accepts.
     *
     * @test
     */
    public function it_migrates_a_named_script_tool_into_a_valid_v3_config()
    {
        file_put_contents($this->configPath, "<?php\nreturn " . var_export([
            'Tools' => ['my-script'],
            'script' => [
                'name' => 'my-script',
                'executablePath' => 'echo',
                'otherArguments' => 'Script tool works!',
            ],
        ], true) . ";\n");

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);
        $this->assertEquals(0, $exitCode);

        $migrated = strval(file_get_contents($this->configPath));
        $this->assertStringContainsString("'type' => 'custom'", $migrated);
        $this->assertStringContainsString("'script' => 'echo Script tool works!'", $migrated);
        $this->assertStringNotContainsString("'type' => 'my-script'", $migrated);

        // The generated file must be usable, not merely written.
        passthru("$this->githooks conf:check --config=$this->configPath 2>/dev/null", $checkExit);
        $this->assertSame(0, $checkExit, 'The migrated configuration must pass conf:check');
    }

    /** @test A migration whose output is invalid reports it instead of claiming success */
    public function it_fails_when_the_generated_config_is_invalid()
    {
        file_put_contents(
            $this->configPath,
            "<?php\nreturn ['Tools' => ['not-a-real-tool'], 'not-a-real-tool' => ['paths' => ['src']]];\n"
        );

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('is not a supported tool', $this->getActualOutput());
        $this->assertFileExists($this->configPath . '.v2.bak');
    }

    /**
     * Migrated files used to carry the four camelCase keys verbatim, so the
     * result warned about deprecations the first time it was read.
     *
     * @test
     */
    public function it_migrates_deprecated_keys_to_their_canonical_form()
    {
        file_put_contents($this->configPath, "<?php\nreturn " . var_export([
            'Tools' => ['phpcs'],
            'phpcs' => ['executablePath' => 'vendor/bin/phpcs', 'otherArguments' => '-p', 'paths' => ['src']],
        ], true) . ";\n");

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);
        $this->assertEquals(0, $exitCode);

        $migrated = strval(file_get_contents($this->configPath));
        $this->assertStringContainsString("'executable-path' =>", $migrated);
        $this->assertStringContainsString("'other-arguments' =>", $migrated);
        $this->assertStringNotContainsString("'executablePath' =>", $migrated);
        $this->assertStringNotContainsString("'otherArguments' =>", $migrated);
    }

    /** @test */
    public function it_detects_v3_format()
    {
        $this->configurationFileBuilder->enableV3Mode();

        file_put_contents(
            $this->configPath,
            $this->configurationFileBuilder->buildV3Php()
        );

        passthru("$this->githooks conf:migrate --config=$this->configPath", $exitCode);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('already in v3', $this->getActualOutput());
    }
}
