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

    /**
     * @test
     * Kills L29 Continue→break: with two errors, the first invalid and the second
     * valid, `break` would stop the loop and return no issues. The assert on both
     * count and identity (line number of the surviving error) forces the mutant.
     */
    function it_keeps_parsing_after_skipping_an_invalid_error_entry()
    {
        $json = json_encode([
            'results' => [
                'errors' => [
                    ['type' => 'SyntaxError'], // invalid: missing file/line/message
                    ['file' => '/abs/Good.php', 'line' => 77, 'message' => 'good'],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'parallel-lint');

        $this->assertCount(1, $issues);
        $this->assertSame(77, $issues[0]->getLine());
        $this->assertSame('good', $issues[0]->getMessage());
    }

    /**
     * @test
     * Kills L46 ArrayOneItem: if the returned array is truncated to one item,
     * the second valid issue disappears.
     */
    function it_returns_all_valid_issues_when_multiple_errors_are_present()
    {
        $json = json_encode([
            'results' => [
                'errors' => [
                    ['file' => '/abs/One.php', 'line' => 1, 'message' => 'first'],
                    ['file' => '/abs/Two.php', 'line' => 2, 'message' => 'second'],
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'parallel-lint');

        $this->assertCount(2, $issues);
        $this->assertSame('first', $issues[0]->getMessage());
        $this->assertSame(1, $issues[0]->getLine());
        $this->assertSame('second', $issues[1]->getMessage());
        $this->assertSame(2, $issues[1]->getLine());
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
