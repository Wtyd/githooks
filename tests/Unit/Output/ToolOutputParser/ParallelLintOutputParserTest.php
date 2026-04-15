<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\ParallelLintOutputParser;

class ParallelLintOutputParserTest extends TestCase
{
    private ParallelLintOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ParallelLintOutputParser();
    }

    /** @test */
    function it_parses_parallel_lint_json_with_errors()
    {
        $cwd = getcwd();
        $json = json_encode([
            'results' => [
                'errors' => [
                    [
                        'type' => 'SyntaxError',
                        'file' => $cwd . '/src/Broken.php',
                        'line' => 42,
                        'message' => 'Syntax error, unexpected T_STRING',
                    ],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'parallel-lint');

        $this->assertCount(1, $issues);
        $this->assertSame('src/Broken.php', $issues[0]->getFile());
        $this->assertSame(42, $issues[0]->getLine());
        $this->assertSame('error', $issues[0]->getSeverity());
        $this->assertSame('SyntaxError', $issues[0]->getRuleId());
    }

    /** @test */
    function it_returns_empty_for_clean_run()
    {
        $json = json_encode(['results' => ['errors' => []]]);

        $this->assertSame([], $this->parser->parse($json, 'parallel-lint'));
    }

    /** @test */
    function it_returns_empty_for_invalid_json()
    {
        $this->assertSame([], $this->parser->parse('not json', 'parallel-lint'));
    }
}
