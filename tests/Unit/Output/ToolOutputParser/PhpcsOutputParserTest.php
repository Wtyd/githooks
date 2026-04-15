<?php

declare(strict_types=1);

namespace Tests\Unit\Output\ToolOutputParser;

use PHPUnit\Framework\TestCase;
use Wtyd\GitHooks\Output\ToolOutputParser\PhpcsOutputParser;

class PhpcsOutputParserTest extends TestCase
{
    private PhpcsOutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhpcsOutputParser();
    }

    /** @test */
    function it_parses_phpcs_json_with_errors_and_warnings()
    {
        $json = json_encode([
            'totals' => ['errors' => 1, 'warnings' => 1],
            'files' => [
                'src/Foo.php' => [
                    'errors' => 1,
                    'warnings' => 1,
                    'messages' => [
                        [
                            'message' => 'Line exceeds 120 characters',
                            'source' => 'Generic.Files.LineLength.TooLong',
                            'severity' => 5,
                            'fixable' => false,
                            'type' => 'WARNING',
                            'line' => 42,
                            'column' => 1,
                        ],
                        [
                            'message' => 'Missing file doc comment',
                            'source' => 'PSR12.Files.FileHeader.Missing',
                            'severity' => 5,
                            'fixable' => false,
                            'type' => 'ERROR',
                            'line' => 1,
                            'column' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertCount(2, $issues);
        $this->assertSame('warning', $issues[0]->getSeverity());
        $this->assertSame('Generic.Files.LineLength.TooLong', $issues[0]->getRuleId());
        $this->assertSame(42, $issues[0]->getLine());
        $this->assertSame(1, $issues[0]->getColumn());
        $this->assertSame('error', $issues[1]->getSeverity());
        $this->assertSame('PSR12.Files.FileHeader.Missing', $issues[1]->getRuleId());
    }

    /** @test */
    function it_returns_empty_for_clean_run()
    {
        $json = json_encode(['totals' => ['errors' => 0, 'warnings' => 0], 'files' => []]);

        $this->assertSame([], $this->parser->parse($json, 'phpcs'));
    }

    /** @test */
    function it_returns_empty_for_invalid_json()
    {
        $this->assertSame([], $this->parser->parse('not json', 'phpcs'));
    }
}
