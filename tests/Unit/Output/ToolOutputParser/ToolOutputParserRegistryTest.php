<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpcsOutputParser;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpmdOutputParser;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpstanOutputParser;
use Wtyd\GitHooks\Output\ToolOutputParser\PsalmOutputParser;
use Wtyd\GitHooks\Output\ToolOutputParser\ParallelLintOutputParser;
use Wtyd\GitHooks\Output\ToolOutputParser\ToolOutputParserRegistry;

class ToolOutputParserRegistryTest extends TestCase
{
    private ToolOutputParserRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolOutputParserRegistry();
    }

    /** @test */
    function it_returns_correct_parser_for_each_supported_type()
    {
        $this->assertInstanceOf(PhpstanOutputParser::class, $this->registry->getParser('phpstan'));
        $this->assertInstanceOf(PhpcsOutputParser::class, $this->registry->getParser('phpcs'));
        $this->assertInstanceOf(PsalmOutputParser::class, $this->registry->getParser('psalm'));
        $this->assertInstanceOf(PhpmdOutputParser::class, $this->registry->getParser('phpmd'));
        $this->assertInstanceOf(ParallelLintOutputParser::class, $this->registry->getParser('parallel-lint'));
    }

    /** @test */
    function it_returns_null_for_unsupported_types()
    {
        $this->assertNull($this->registry->getParser('phpunit'));
        $this->assertNull($this->registry->getParser('phpcpd'));
        $this->assertNull($this->registry->getParser('phpcbf'));
        $this->assertNull($this->registry->getParser('php-cs-fixer'));
        $this->assertNull($this->registry->getParser('rector'));
        $this->assertNull($this->registry->getParser('script'));
        $this->assertNull($this->registry->getParser('custom'));
        $this->assertNull($this->registry->getParser('nonexistent'));
    }

    /** @test */
    function has_parser_returns_correct_boolean()
    {
        $this->assertTrue($this->registry->hasParser('phpstan'));
        $this->assertTrue($this->registry->hasParser('phpcs'));
        $this->assertFalse($this->registry->hasParser('phpunit'));
        $this->assertFalse($this->registry->hasParser('nonexistent'));
    }
}
