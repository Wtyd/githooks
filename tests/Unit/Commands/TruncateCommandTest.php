<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Tests\Utils\TestCase\UnitTestCase;
use Wtyd\GitHooks\Configuration\ConfigurationChecker;

/**
 * Tests for the command-truncation rule. Phase 3b moved the rule into
 * {@see ConfigurationChecker::truncateCommand()} (pure function) so it no
 * longer needs reflection on CheckConfigurationFileCommand to be exercised.
 */
class TruncateCommandTest extends UnitTestCase
{
    private ConfigurationChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new ConfigurationChecker();
    }

    /** @test */
    public function it_does_not_truncate_short_commands()
    {
        $command = 'vendor/bin/phpstan analyse -c phpstan.neon src';
        $result = $this->checker->truncateCommand($command);

        $this->assertEquals($command, $result);
    }

    /** @test */
    public function it_does_not_truncate_at_exactly_80_chars()
    {
        $command = str_repeat('x', 80);
        $result = $this->checker->truncateCommand($command);

        $this->assertEquals($command, $result);
        $this->assertEquals(80, strlen($result));
    }

    /** @test */
    public function it_truncates_commands_longer_than_80_chars()
    {
        $command = str_repeat('x', 100);
        $result = $this->checker->truncateCommand($command);

        $this->assertEquals(80, strlen($result));
        $this->assertStringEndsWith('...', $result);
        $this->assertEquals(str_repeat('x', 77) . '...', $result);
    }

    /** @test */
    public function it_truncates_realistic_long_command()
    {
        $command = 'vendor/bin/phpcpd --exclude vendor --exclude tools --exclude config '
            . '--exclude qa --exclude bootstrap --exclude database --exclude storage '
            . '--exclude tests --exclude resources --exclude public ./';

        $result = $this->checker->truncateCommand($command);

        $this->assertEquals(80, strlen($result));
        $this->assertStringEndsWith('...', $result);
        $this->assertStringStartsWith('vendor/bin/phpcpd', $result);
    }

    /** @test */
    public function it_respects_custom_max_length()
    {
        $command = str_repeat('x', 50);
        $result = $this->checker->truncateCommand($command, 30);

        $this->assertEquals(30, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    /** @test */
    public function it_handles_empty_string()
    {
        $this->assertEquals('', $this->checker->truncateCommand(''));
    }
}
