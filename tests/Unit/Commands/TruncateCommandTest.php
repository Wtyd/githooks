<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wtyd\GitHooks\App\Commands\CheckConfigurationFileCommand;

class TruncateCommandTest extends TestCase
{
    private ReflectionMethod $method;

    protected function setUp(): void
    {
        $this->method = new ReflectionMethod(CheckConfigurationFileCommand::class, 'truncateCommand');
        $this->method->setAccessible(true);
    }

    /** @test */
    public function it_does_not_truncate_short_commands()
    {
        $command = 'vendor/bin/phpstan analyse -c phpstan.neon src';
        $result = $this->truncate($command);

        $this->assertEquals($command, $result);
    }

    /** @test */
    public function it_does_not_truncate_at_exactly_80_chars()
    {
        $command = str_repeat('x', 80);
        $result = $this->truncate($command);

        $this->assertEquals($command, $result);
        $this->assertEquals(80, strlen($result));
    }

    /** @test */
    public function it_truncates_commands_longer_than_80_chars()
    {
        $command = str_repeat('x', 100);
        $result = $this->truncate($command);

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

        $result = $this->truncate($command);

        $this->assertEquals(80, strlen($result));
        $this->assertStringEndsWith('...', $result);
        $this->assertStringStartsWith('vendor/bin/phpcpd', $result);
    }

    /** @test */
    public function it_respects_custom_max_length()
    {
        $command = str_repeat('x', 50);
        $result = $this->truncate($command, 30);

        $this->assertEquals(30, strlen($result));
        $this->assertStringEndsWith('...', $result);
    }

    /** @test */
    public function it_handles_empty_string()
    {
        $this->assertEquals('', $this->truncate(''));
    }

    private function truncate(string $command, int $maxLength = 80): string
    {
        // Create a minimal instance via reflection (skip constructor)
        $class = new \ReflectionClass(CheckConfigurationFileCommand::class);
        $instance = $class->newInstanceWithoutConstructor();

        if ($maxLength !== 80) {
            return $this->method->invoke($instance, $command, $maxLength);
        }

        return $this->method->invoke($instance, $command);
    }
}
