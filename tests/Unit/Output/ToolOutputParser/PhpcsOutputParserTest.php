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

    /** @test */
    function it_returns_empty_for_empty_string()
    {
        $this->assertSame([], $this->parser->parse('', 'phpcs'));
    }

    /** @test */
    function it_returns_empty_when_array_missing_files_key()
    {
        $json = json_encode(['totals' => ['errors' => 0]]);

        $this->assertSame([], $this->parser->parse($json, 'phpcs'));
    }

    /** @test */
    function it_skips_file_entries_missing_messages_key()
    {
        $json = json_encode(['files' => ['src/A.php' => ['errors' => 1]]]);

        $this->assertSame([], $this->parser->parse($json, 'phpcs'));
    }

    /** @test */
    function it_skips_file_entries_that_are_not_arrays()
    {
        $json = json_encode(['files' => ['src/A.php' => 'broken']]);

        $this->assertSame([], $this->parser->parse($json, 'phpcs'));
    }

    /**
     * @test
     * @dataProvider messageMissingKeyProvider
     */
    function it_skips_message_entries_missing_required_keys(array $message)
    {
        $json = json_encode(['files' => ['src/A.php' => ['messages' => [$message]]]]);

        $this->assertSame([], $this->parser->parse($json, 'phpcs'));
    }

    public function messageMissingKeyProvider(): array
    {
        return [
            'missing line' => [['message' => 'x', 'type' => 'ERROR']],
            'missing message' => [['line' => 1, 'type' => 'ERROR']],
        ];
    }

    /** @test */
    function it_treats_lowercase_error_type_as_error_severity()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['line' => 1, 'message' => 'x', 'type' => 'error'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertSame('error', $issues[0]->getSeverity());
    }

    /** @test */
    function it_defaults_severity_to_warning_when_type_missing()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['line' => 1, 'message' => 'x'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertSame('warning', $issues[0]->getSeverity());
    }

    /** @test */
    function it_casts_line_and_column_to_int()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['line' => '7', 'column' => '3', 'message' => 'x', 'type' => 'ERROR'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertSame(7, $issues[0]->getLine());
        $this->assertSame(3, $issues[0]->getColumn());
    }

    /**
     * @test
     * Kills L30 Continue→break: with two files, the first invalid and the second
     * valid, `break` would stop the outer loop before processing the second file.
     */
    function it_keeps_parsing_after_skipping_an_invalid_file_entry()
    {
        $json = json_encode([
            'files' => [
                'src/Broken.php' => 'not-an-array',
                'src/Good.php'   => ['messages' => [
                    ['line' => 42, 'message' => 'good', 'type' => 'ERROR'],
                ]
                ],
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertCount(1, $issues);
        $this->assertSame('src/Good.php', $issues[0]->getFile());
        $this->assertSame(42, $issues[0]->getLine());
    }

    /**
     * @test
     * Kills L34 Continue→break: two messages in one file, first invalid and second
     * valid — `break` drops the second.
     */
    function it_keeps_parsing_after_skipping_an_invalid_message_entry()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['type' => 'ERROR'], // invalid: no line/message
                ['line' => 99, 'message' => 'surviving', 'type' => 'ERROR'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertCount(1, $issues);
        $this->assertSame(99, $issues[0]->getLine());
        $this->assertSame('surviving', $issues[0]->getMessage());
    }

    /** @test */
    function it_defaults_source_to_phpcs_when_missing()
    {
        $json = json_encode([
            'files' => ['src/A.php' => ['messages' => [
                ['line' => 1, 'message' => 'x', 'type' => 'ERROR'],
            ]
            ]
            ],
        ]);

        $issues = $this->parser->parse($json, 'phpcs');

        $this->assertSame('phpcs', $issues[0]->getRuleId());
    }
}
