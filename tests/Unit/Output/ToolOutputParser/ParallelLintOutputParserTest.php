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

    /** @test */
    function it_returns_empty_for_empty_string()
    {
        $this->assertSame([], $this->parser->parse('', 'parallel-lint'));
    }

    /** @test */
    function it_returns_empty_when_array_missing_results_key()
    {
        $json = json_encode(['other' => 'value']);

        $this->assertSame([], $this->parser->parse($json, 'parallel-lint'));
    }

    /** @test */
    function it_returns_empty_when_results_missing_errors_key()
    {
        $json = json_encode(['results' => ['other' => []]]);

        $this->assertSame([], $this->parser->parse($json, 'parallel-lint'));
    }

    /** @test */
    function it_skips_error_entries_that_are_not_arrays()
    {
        $json = json_encode(['results' => ['errors' => ['not-an-array']]]);

        $this->assertSame([], $this->parser->parse($json, 'parallel-lint'));
    }

    /**
     * @test
     * @dataProvider errorMissingKeyProvider
     */
    function it_skips_error_entries_missing_required_keys(array $error)
    {
        $json = json_encode(['results' => ['errors' => [$error]]]);

        $this->assertSame([], $this->parser->parse($json, 'parallel-lint'));
    }

    public function errorMissingKeyProvider(): array
    {
        return [
            'missing file' => [['line' => 1, 'message' => 'x']],
            'missing line' => [['file' => '/p.php', 'message' => 'x']],
            'missing message' => [['file' => '/p.php', 'line' => 1]],
        ];
    }

    /** @test */
    function it_makes_relative_path_when_cwd_has_trailing_slash()
    {
        $cwd = getcwd();
        $json = json_encode([
            'results' => [
                'errors' => [
                    ['file' => $cwd . '/src/X.php', 'line' => 1, 'message' => 'boom'],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'parallel-lint');

        $this->assertCount(1, $issues);
        $this->assertSame('src/X.php', $issues[0]->getFile());
    }
}
